<?php

namespace App\Commands;

use App\Exceptions\InvalidOriginDestinationException;
use App\Exceptions\RemoteBlockedError;
use App\Exceptions\RemoteError;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

use DiDom\Document;

use LaravelZero\Framework\Commands\Command;

use Exception;

class UpdateSchedule extends Command
{

    const CACHE_FILENAME = 'schedule_%s_data.json';
    const SOURCE_SELECTOR = 'form[action*="horarios"]';
    const RESULT_SELECTOR = '.fv-res-table';
    const MAX_REASONABLE_TRAVEL_MINUTES = 180;
    const STATION_QUERY_ALIASES = [
        'Boulogne Sur Mer' => 'Boulogne',
    ];
    const SCHEDULE_SEGMENT_QUERY_VALUES = [
        '1' => 'lv',
        '2' => 'sab',
        '3' => 'dom',
    ];
    const DEFAULT_REQUEST_DELAY_MS = 1500;
    const DEFAULT_REQUEST_JITTER_MS = 500;
    const DEFAULT_BLOCK_BACKOFF_SECONDS = 90;
    const DEFAULT_BLOCK_MAX_RETRIES = 2;
    const DEFAULT_USER_AGENT = 'belgranowear-updater/1.0 (+https://github.com/belgranowear/belgranowear.github.io)';
    const REQUEST_TIMEOUT_SECONDS = 20;
    const REQUEST_CONNECT_TIMEOUT_SECONDS = 5;
    const MAX_CONSECUTIVE_REMOTE_ERRORS = 5;
    const MAX_FORMATION_STOP_GAP_MINUTES = 60;
    const PROGRESS_LOG_EVERY = 10;
    const BOT_CHALLENGE_MARKERS = [
        'Checking your browser',
        'Javascript required',
    ];

    private array $availabilityOptions = [];
    private array $stationIdsByQueryName = [];
    private ?float $lastRemoteRequestAt = null;
    private int $remoteRequests = 0;
    private int $consecutiveRemoteErrors = 0;
    private float $startedAt = 0.0;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'app:update-schedule';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Updates the schedule references from Ferrovias\' official website';
    
    /**
     * buildFilename
     *
     * @param  string $scheduleSegment
     * @param  string $origin
     * @param  string $destination
     * 
     * @throws InvalidOriginDestinationException
     * 
     * @return string
     */
    private function buildFilename(string $scheduleSegment, string $origin, string $destination): string {
        if (
            strlen($scheduleSegment) == 0
            ||
            strlen($origin)          == 0
            ||
            strlen($destination)     == 0
        ) {
            throw new InvalidOriginDestinationException('Invalid filename specification.');
        }

        return sprintf(self::CACHE_FILENAME, "{$scheduleSegment}.{$origin}.{$destination}");
    }
    
    /**
     * queryStationPair
     *
     * @param  array $query
     * 
     * @throws RemoteError
     * 
     * @return array
     */
    private function queryStationPair(array $query): array {
        $response = $this->queryRemoteSchedule($query);
        $table = $this->resultTableFromResponse($response, $query);
        $trainRows = [];

        foreach ($table->find('tr') as $tr) {
            $cells = $tr->find('td');

            if (count($cells) < 3) {
                continue;
            }

            $formation = trim($cells[0]->text());
            $departure = $this->normalizeTime($cells[1]->text());
            $arrival = $this->normalizeTime($cells[2]->text());

            if (!$this->isReasonableTravelDuration($departure, $arrival)) {
                if ($this->debugQueries()) {
                    $this->warn(
                        __METHOD__ . ': skipping implausible schedule row for query ' .
                        json_encode($query) . ': ' . json_encode([$departure, $arrival])
                    );
                }

                continue;
            }

            if ($formation === '') {
                continue;
            }

            $trainRows[] = [
                'formation' => $formation,
                'departure' => $departure,
                'arrival' => $arrival,
            ];
        }

        if ($this->debugQueries()) {
            $this->comment('R = ' . json_encode($trainRows));
        }

        if (empty($trainRows)) {
            throw new RemoteError(
                __METHOD__ . ': couldn\'t find any usable train rows for query: ' . json_encode($query) .
                PHP_EOL .
                $this->buildSourceDiagnostics(
                    response: $response,
                    exception: null,
                    sourceUrl: env('RESULTS_FORM_URL'),
                    expectedSelector: self::RESULT_SELECTOR,
                )
            );
        }

        return $trainRows;
    }

    private function queryFormation(string $formation, string $scheduleSegmentQueryValue): array
    {
        $query = [
            'formacion' => $formation,
            'tipo_dia_form' => $scheduleSegmentQueryValue,
        ];
        $response = $this->queryRemoteSchedule($query);
        $table = $this->resultTableFromResponse($response, $query);
        $stationIdsByQueryName = $this->stationIdsByQueryName();
        $formationRows = [];

        foreach ($table->find('tr') as $tr) {
            $cells = $tr->find('td');

            if (count($cells) < 2) {
                continue;
            }

            $stationName = trim($cells[0]->text());
            $stationKey = $this->normalizeStationKey($stationName);

            if (!isset($stationIdsByQueryName[$stationKey])) {
                throw new RemoteError(
                    "Formation {$formation} contains unknown station '{$stationName}' for segment " .
                    "{$scheduleSegmentQueryValue}." . PHP_EOL .
                    $this->buildSourceDiagnostics(
                        response: $response,
                        exception: null,
                        sourceUrl: env('RESULTS_FORM_URL'),
                        expectedSelector: self::RESULT_SELECTOR,
                    )
                );
            }

            if (in_array($stationIdsByQueryName[$stationKey], array_column($formationRows, 'stationId'), true)) {
                throw new RemoteError(
                    "Formation {$formation} lists station '{$stationName}' more than once for segment " .
                    "{$scheduleSegmentQueryValue}; refusing to expand what looks like more than one trip."
                );
            }

            $formationRows[] = [
                'stationId' => $stationIdsByQueryName[$stationKey],
                'stationName' => $stationName,
                'time' => $this->normalizeTime($cells[1]->text()),
            ];
        }

        if (count($formationRows) < 2) {
            throw new RemoteError(
                "Formation {$formation} did not contain enough station rows for segment {$scheduleSegmentQueryValue}." .
                PHP_EOL .
                $this->buildSourceDiagnostics(
                    response: $response,
                    exception: null,
                    sourceUrl: env('RESULTS_FORM_URL'),
                    expectedSelector: self::RESULT_SELECTOR,
                )
            );
        }

        $formationRows = $this->formationRowsInTravelOrder(
            formationRows: $formationRows,
            formation: $formation,
            scheduleSegmentQueryValue: $scheduleSegmentQueryValue,
        );

        if ($this->debugQueries()) {
            $this->comment("F {$scheduleSegmentQueryValue}:{$formation} = " . json_encode($formationRows));
        }

        return $formationRows;
    }

    private function queryRemoteSchedule(array $query): Response
    {
        if ($this->debugQueries()) {
            $this->comment('Q = ' . json_encode($query));
        }

        try {
            $response = $this->sendRemoteRequest(
                url:    env('RESULTS_FORM_URL'),
                query:  $query
            );
        } catch (RemoteBlockedError $remoteBlockedError) {
            throw $remoteBlockedError;
        } catch (\Throwable $exception) {
            throw new RemoteError(
                "Request failed for query " . json_encode($query) . ': ' .
                get_class($exception) . ' - ' . $exception->getMessage()
            );
        }

        if (!$response->successful()) {
            $this->warn(
                __METHOD__ . ': couldn\'t load results for query: ' . json_encode($query)
            );

            throw new RemoteError(
                "[{$response->status()}] <- " . json_encode($query) . PHP_EOL .
                $this->buildSourceDiagnostics(
                    response: $response,
                    exception: null,
                    sourceUrl: env('RESULTS_FORM_URL'),
                )
            );
        }

        return $response;
    }

    private function resultTableFromResponse(Response $response, array $query)
    {
        $document = new Document( $response->body() );
        $table = $document->first(self::RESULT_SELECTOR);

        if (!$table) {
            throw new RemoteError(
                'Schedule response did not contain the expected "' . self::RESULT_SELECTOR . '" table for query ' .
                json_encode($query) . PHP_EOL .
                $this->buildSourceDiagnostics(
                    response: $response,
                    exception: null,
                    sourceUrl: env('RESULTS_FORM_URL'),
                    expectedSelector: self::RESULT_SELECTOR,
                )
            );
        }

        return $table;
    }
    
    /**
     * loadAvailabilityOptions
     *
     * @throws Exception
     * 
     * @return void
     */
    private function loadAvailabilityOptions(): void {
        if (Storage::exists( UpdateAvailabilityOptions::CACHE_FILENAME )) {
            try {
                $this->availabilityOptions = json_decode(
                    json:        Storage::get( UpdateAvailabilityOptions::CACHE_FILENAME ),
                    associative: true,
                    flags:       JSON_THROW_ON_ERROR
                );
            } catch (Exception $exception) {
                $this->warn(
                    __METHOD__ . ": failed to prepare cached data for patching: {$exception->getMessage()}" . PHP_EOL .
                    $exception->getTraceAsString()
                );
            }
        }

        if (!$this->availabilityOptions) {
            throw new Exception('No availability options found, please try again later.');
        }
    }

    private function saveSchedule(array $schedule, string $filename): void {
        if (empty($schedule)) {
            $this->warn(
                __METHOD__ . ': couldn\'t save empty schedule for filename: ' . $filename
            );

            return;
        }

        Storage::put(
            path:       $filename,
            contents:   json_encode(
                value: $schedule,
                flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
            )
        );
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->lastRemoteRequestAt = null;
        $this->remoteRequests = 0;
        $this->consecutiveRemoteErrors = 0;
        $this->stationIdsByQueryName = [];
        $this->startedAt = microtime(true);

        $this->comment(
            'Throttling Ferrovias requests to one every ' .
            $this->envInt('SCHEDULE_REQUEST_DELAY_MS', self::DEFAULT_REQUEST_DELAY_MS) . 'ms (+ up to ' .
            $this->envInt('SCHEDULE_REQUEST_JITTER_MS', self::DEFAULT_REQUEST_JITTER_MS) . 'ms jitter); ' .
            'progress is logged every ' . self::PROGRESS_LOG_EVERY . ' queries.'
        );

        $sourceUrl = env('RESULTS_FORM_URL');

        [$baseFormResponse, $exception] = $this->fetchSource($sourceUrl);

        if ($exception instanceof RemoteBlockedError) {
            $this->error('Aborting schedule update: Ferrovias is blocking the updater on the base form request.');
            $this->line($exception->getMessage());

            return Command::FAILURE;
        }

        if ($exception || !$baseFormResponse?->successful()) {
            return $this->failScheduleUpdate(
                response: $baseFormResponse,
                exception: $exception,
                sourceUrl: $sourceUrl,
            );
        }

        $document = new Document( $baseFormResponse->body() );

        $optionsContainer = $document->first(self::SOURCE_SELECTOR);

        if (!$optionsContainer) {
            return $this->failScheduleUpdate(
                response: $baseFormResponse,
                exception: null,
                sourceUrl: $sourceUrl,
            );
        }

        try {
            $this->loadAvailabilityOptions();
        } catch (Exception $exception) {
            return $this->failScheduleUpdate(
                response: $baseFormResponse,
                exception: $exception,
                sourceUrl: $sourceUrl,
            );
        }

        $failedQueries = 0;
        $totalSeedQueries = 0;
        $totalFormationQueries = 0;

        try {
            $schedules = $this->collectSchedules(
                failedQueries: $failedQueries,
                totalSeedQueries: $totalSeedQueries,
                totalFormationQueries: $totalFormationQueries,
            );
        } catch (RemoteBlockedError $remoteBlockedError) {
            $this->error(
                "Aborting schedule update after {$this->remoteRequests} remote requests: Ferrovias is blocking or not answering the updater. " .
                'Cached schedules were left untouched; the next scheduled retry should run from a fresh runner.'
            );
            $this->line($remoteBlockedError->getMessage());

            return Command::FAILURE;
        }

        if ($failedQueries > 0) {
            $this->error("Schedule update finished with {$failedQueries} failed remote queries.");

            return Command::FAILURE;
        }

        $this->warnAboutMissingSchedules($schedules);

        $savedSchedules = 0;

        try {
            $savedSchedules = $this->saveGeneratedSchedules($schedules);
        } catch (InvalidOriginDestinationException $invalidOriginDestinationException) {
            $this->error(
                "[BUG] {$invalidOriginDestinationException->getMessage()}" . PHP_EOL .
                $invalidOriginDestinationException->getTraceAsString()
            );

            return Command::FAILURE;
        }

        $this->comment(
            "Saved {$savedSchedules} schedule files from {$totalFormationQueries} formation queries " .
            "and {$totalSeedQueries} adjacent station seed queries ({$this->remoteRequests} remote requests)."
        );
        $this->info('Success updating schedule.');

        return Command::SUCCESS;
    }

    /**
     * @throws RemoteBlockedError
     */
    private function collectSchedules(
        int &$failedQueries,
        int &$totalSeedQueries,
        int &$totalFormationQueries,
    ): array {
        $schedules = [];

        foreach (array_keys($this->availabilityOptions['scheduleSegment']) as $scheduleSegment) {
            $scheduleSegmentQueryValue = $this->scheduleSegmentQueryValue($scheduleSegment);
            $failedQueriesBeforeSegment = $failedQueries;
            $seedQueriesBeforeSegment = $totalSeedQueries;
            $formations = $this->discoverFormationsForSegment(
                scheduleSegment: $scheduleSegment,
                scheduleSegmentQueryValue: $scheduleSegmentQueryValue,
                failedQueries: $failedQueries,
                totalSeedQueries: $totalSeedQueries,
            );

            $segmentSeedQueries = $totalSeedQueries - $seedQueriesBeforeSegment;
            $this->comment(
                "Segment {$scheduleSegment} ({$scheduleSegmentQueryValue}): discovered " .
                count($formations) . " formations from {$segmentSeedQueries} adjacent station seed queries " .
                "[{$this->formatDuration(microtime(true) - $this->startedAt)} elapsed]."
            );

            if (empty($formations)) {
                if ($failedQueries === $failedQueriesBeforeSegment) {
                    $failedQueries++;
                    $this->error(
                        "No formations discovered for schedule segment {$scheduleSegment} " .
                        "({$scheduleSegmentQueryValue})."
                    );
                }

                continue;
            }

            $formationsStartedAt = microtime(true);

            foreach ($formations as $formationIndex => $formation) {
                $totalFormationQueries++;

                try {
                    $formationRows = $this->queryFormation($formation, $scheduleSegmentQueryValue);
                    $this->consecutiveRemoteErrors = 0;
                    $addedSchedules = $this->addFormationRowsToSchedules(
                        schedules: $schedules,
                        scheduleSegment: $scheduleSegment,
                        formationRows: $formationRows,
                    );

                    if ($this->debugQueries()) {
                        $this->comment(
                            "Formation {$scheduleSegmentQueryValue}:{$formation} expanded into " .
                            "{$addedSchedules} station-pair schedules."
                        );
                    }
                } catch (RemoteBlockedError $remoteBlockedError) {
                    throw $remoteBlockedError;
                } catch (RemoteError $remoteError) {
                    $failedQueries++;
                    $this->error(
                        "[Remote error] {$remoteError->getMessage()}" . PHP_EOL .
                        $remoteError->getTraceAsString()
                    );
                    $this->registerConsecutiveRemoteError();
                }

                $this->reportProgress(
                    label: "Segment {$scheduleSegment} ({$scheduleSegmentQueryValue}) formations",
                    done: $formationIndex + 1,
                    total: count($formations),
                    phaseStartedAt: $formationsStartedAt,
                    failedQueries: $failedQueries,
                );
            }
        }

        return $schedules;
    }

    private function reportProgress(
        string $label,
        int $done,
        int $total,
        float $phaseStartedAt,
        int $failedQueries,
    ): void {
        if ($done % self::PROGRESS_LOG_EVERY !== 0 && $done !== $total) {
            return;
        }

        $phaseElapsed = microtime(true) - $phaseStartedAt;
        $remaining = $total - $done;
        $eta = $remaining > 0 ? ' · ETA ' . $this->formatDuration($phaseElapsed / $done * $remaining) : '';

        $this->line(
            "{$label}: {$done}/{$total} (" . (int) floor($done * 100 / max($total, 1)) . '%)' .
            " · {$this->remoteRequests} requests · {$failedQueries} failed" .
            ' · ' . $this->formatDuration(microtime(true) - $this->startedAt) . ' elapsed' . $eta
        );
    }

    private function formatDuration(float $seconds): string
    {
        $seconds = (int) round($seconds);

        return $seconds >= 60
            ? sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60)
            : "{$seconds}s";
    }

    private function discoverFormationsForSegment(
        string $scheduleSegment,
        string $scheduleSegmentQueryValue,
        int &$failedQueries,
        int &$totalSeedQueries,
    ): array {
        $formations = [];
        $stationPairs = $this->adjacentStationPairs();
        $seedsStartedAt = microtime(true);

        $this->comment(
            "Segment {$scheduleSegment} ({$scheduleSegmentQueryValue}): querying " . count($stationPairs) .
            ' adjacent station pairs to discover formations...'
        );

        foreach ($stationPairs as $stationPairIndex => $stationPair) {
            $totalSeedQueries++;

            try {
                $trainRows = $this->queryStationPair(
                    $this->stationPairQuery(
                        originName: $stationPair['originName'],
                        destinationName: $stationPair['destinationName'],
                        scheduleSegmentQueryValue: $scheduleSegmentQueryValue,
                    )
                );

                $this->consecutiveRemoteErrors = 0;

                foreach ($trainRows as $trainRow) {
                    $formations[$trainRow['formation']] = true;
                }
            } catch (RemoteBlockedError $remoteBlockedError) {
                throw $remoteBlockedError;
            } catch (RemoteError $remoteError) {
                $failedQueries++;
                $this->error(
                    "[Remote error] segment {$scheduleSegment} seed {$stationPair['originId']}->{$stationPair['destinationId']}: " .
                    "{$remoteError->getMessage()}" . PHP_EOL .
                    $remoteError->getTraceAsString()
                );
                $this->registerConsecutiveRemoteError();
            }

            $this->reportProgress(
                label: "Segment {$scheduleSegment} ({$scheduleSegmentQueryValue}) seeds",
                done: $stationPairIndex + 1,
                total: count($stationPairs),
                phaseStartedAt: $seedsStartedAt,
                failedQueries: $failedQueries,
            );
        }

        $formationNumbers = array_keys($formations);
        usort($formationNumbers, 'strnatcmp');

        return $formationNumbers;
    }

    private function adjacentStationPairs(): array
    {
        $stations = $this->availabilityOptions['origin'] ?? [];
        $stationIds = array_keys($stations);
        $pairs = [];

        for ($index = 0, $count = count($stationIds); $index < $count - 1; $index++) {
            $originId = (string) $stationIds[$index];
            $destinationId = (string) $stationIds[$index + 1];

            $pairs[] = [
                'originId' => $originId,
                'destinationId' => $destinationId,
                'originName' => $stations[$originId],
                'destinationName' => $stations[$destinationId],
            ];
            $pairs[] = [
                'originId' => $destinationId,
                'destinationId' => $originId,
                'originName' => $stations[$destinationId],
                'destinationName' => $stations[$originId],
            ];
        }

        return $pairs;
    }

    private function stationPairQuery(
        string $originName,
        string $destinationName,
        string $scheduleSegmentQueryValue,
    ): array {
        return [
            'origen' => $this->stationQueryName($originName),
            'destino' => $this->stationQueryName($destinationName),
            'tipo_dia' => $scheduleSegmentQueryValue,
            'hora_desde' => array_key_first($this->availabilityOptions['timeFrom']),
            'hora_hasta' => array_key_last($this->availabilityOptions['timeTo']),
        ];
    }

    private function addFormationRowsToSchedules(
        array &$schedules,
        string $scheduleSegment,
        array $formationRows,
    ): int {
        $addedSchedules = 0;
        $rowCount = count($formationRows);

        for ($originIndex = 0; $originIndex < $rowCount - 1; $originIndex++) {
            for ($destinationIndex = $originIndex + 1; $destinationIndex < $rowCount; $destinationIndex++) {
                $origin = (string) $formationRows[$originIndex]['stationId'];
                $destination = (string) $formationRows[$destinationIndex]['stationId'];

                if ($origin === $destination) {
                    continue;
                }

                $departure = $formationRows[$originIndex]['time'];
                $arrival = $formationRows[$destinationIndex]['time'];

                if (!$this->isReasonableTravelDuration($departure, $arrival)) {
                    if ($this->debugQueries()) {
                        $this->warn(
                            __METHOD__ . ': skipping implausible expanded formation row for ' .
                            "{$scheduleSegment}.{$origin}.{$destination}: " .
                            json_encode([$departure, $arrival])
                        );
                    }

                    continue;
                }

                $schedules["{$scheduleSegment}.{$origin}.{$destination}"][] = [$departure, $arrival];
                $addedSchedules++;
            }
        }

        return $addedSchedules;
    }

    private function formationRowsInTravelOrder(
        array $formationRows,
        string $formation,
        string $scheduleSegmentQueryValue,
    ): array {
        if ($this->hasPlausibleStopGaps($formationRows)) {
            return $formationRows;
        }

        $reversedRows = array_reverse($formationRows);

        if ($this->hasPlausibleStopGaps($reversedRows)) {
            if ($this->debugQueries()) {
                $this->warn(
                    "Formation {$scheduleSegmentQueryValue}:{$formation} rows are listed in reverse travel order; " .
                    'reversing rows before expansion.'
                );
            }

            return $reversedRows;
        }

        // Neither order is a plausible trip (e.g. rows sorted by clock time across
        // midnight): refuse to expand it rather than publish wrong station pairs.
        throw new RemoteError(
            "Formation {$formation} for segment {$scheduleSegmentQueryValue} has stop times that are not in " .
            'travel order: ' . json_encode(array_map(
                fn (array $row) => [$row['stationName'], $row['time']],
                $formationRows
            ), JSON_UNESCAPED_UNICODE)
        );
    }

    private function hasPlausibleStopGaps(array $formationRows): bool
    {
        $totalMinutes = 0;

        for ($index = 0, $count = count($formationRows); $index < $count - 1; $index++) {
            $currentMinutes = $this->parseTimeToMinutes($formationRows[$index]['time']);
            $nextMinutes = $this->parseTimeToMinutes($formationRows[$index + 1]['time']);

            if ($currentMinutes === null || $nextMinutes === null) {
                return false;
            }

            $gapMinutes = ($nextMinutes - $currentMinutes + 24 * 60) % (24 * 60);

            if ($gapMinutes > self::MAX_FORMATION_STOP_GAP_MINUTES) {
                return false;
            }

            $totalMinutes += $gapMinutes;
        }

        return $totalMinutes <= self::MAX_REASONABLE_TRAVEL_MINUTES;
    }

    /**
     * Pairs no formation covers keep the file published by a previous run, so
     * surface them in the action log instead of silently serving stale data.
     */
    private function warnAboutMissingSchedules(array $schedules): void
    {
        $missingSchedules = [];

        foreach (array_keys($this->availabilityOptions['scheduleSegment']) as $scheduleSegment) {
            foreach (array_keys($this->availabilityOptions['origin']) as $origin) {
                foreach (array_keys($this->availabilityOptions['destination']) as $destination) {
                    if ((string) $origin === (string) $destination) {
                        continue;
                    }

                    if (!isset($schedules["{$scheduleSegment}.{$origin}.{$destination}"])) {
                        $missingSchedules[] = "{$scheduleSegment}.{$origin}.{$destination}";
                    }
                }
            }
        }

        if (empty($missingSchedules)) {
            return;
        }

        $this->warn(
            count($missingSchedules) . ' station pairs got no trains from any formation; their previously ' .
            'published schedules were kept: ' . implode(', ', array_slice($missingSchedules, 0, 20)) .
            (count($missingSchedules) > 20 ? ', ...' : '')
        );
        $this->line(
            '::warning title=Schedule pairs not refreshed::' . count($missingSchedules) .
            ' station pairs kept their previous schedule files.'
        );
    }

    private function saveGeneratedSchedules(array $schedules): int
    {
        $savedSchedules = 0;
        ksort($schedules, SORT_NATURAL);

        foreach ($schedules as $scheduleKey => $schedule) {
            [$scheduleSegment, $origin, $destination] = explode('.', $scheduleKey);
            $filename = $this->buildFilename(
                scheduleSegment: $scheduleSegment,
                origin: $origin,
                destination: $destination,
            );

            $this->saveSchedule(
                schedule: $this->sortedUniqueSchedule($schedule),
                filename: $filename,
            );

            $savedSchedules++;
            $this->comment('  => ' . $filename);
        }

        return $savedSchedules;
    }

    private function sortedUniqueSchedule(array $schedule): array
    {
        $uniqueSchedules = [];

        foreach ($schedule as $scheduleRow) {
            $uniqueSchedules["{$scheduleRow[0]}|{$scheduleRow[1]}"] = $scheduleRow;
        }

        $sortedSchedule = array_values($uniqueSchedules);
        usort(
            $sortedSchedule,
            fn (array $left, array $right) => (
                $this->timeToMinutes($left[0]) <=> $this->timeToMinutes($right[0])
            ) ?: (
                $this->timeToMinutes($left[1]) <=> $this->timeToMinutes($right[1])
            )
        );

        return $sortedSchedule;
    }

    private function fetchSource(?string $sourceUrl): array
    {
        if (!$sourceUrl) {
            return [
                null,
                new \RuntimeException('RESULTS_FORM_URL is not configured.'),
            ];
        }

        try {
            return [
                $this->sendRemoteRequest($sourceUrl),
                null,
            ];
        } catch (\Throwable $exception) {
            return [null, $exception];
        }
    }

    /**
     * Sends a throttled request to Ferrovias, backing off when its anti-bot
     * protection answers with a browser challenge instead of the real page.
     *
     * @throws RemoteBlockedError when the challenge persists after every retry
     */
    private function sendRemoteRequest(string $url, array $query = []): Response
    {
        $maxRetries = $this->envInt('SCHEDULE_BLOCK_MAX_RETRIES', self::DEFAULT_BLOCK_MAX_RETRIES);
        $backoffSeconds = $this->envInt('SCHEDULE_BLOCK_BACKOFF_SECONDS', self::DEFAULT_BLOCK_BACKOFF_SECONDS);

        for ($attempt = 0; ; $attempt++) {
            $this->throttleRemoteRequest();

            try {
                $response = Http::withUserAgent(env('SCHEDULE_USER_AGENT') ?: self::DEFAULT_USER_AGENT)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->connectTimeout(self::REQUEST_CONNECT_TIMEOUT_SECONDS)
                    ->retry(
                        times: 3,
                        sleepMilliseconds: 5 * 1000,
                        when: fn (\Exception $exception) => $this->shouldRetry($exception),
                        throw: false,
                    )
                    ->get($url, $query);
            } finally {
                $this->lastRemoteRequestAt = microtime(true);
            }

            if (!$this->isBotChallenge($response)) {
                return $response;
            }

            if ($attempt >= $maxRetries) {
                throw new RemoteBlockedError(
                    "Ferrovias anti-bot protection blocked request #{$this->remoteRequests} " .
                    'after ' . ($attempt + 1) . ' attempts for query ' . json_encode($query) . PHP_EOL .
                    $this->buildSourceDiagnostics(
                        response: $response,
                        exception: null,
                        sourceUrl: $url,
                    )
                );
            }

            $waitSeconds = $backoffSeconds * ($attempt + 1);
            $this->warn(
                "Ferrovias anti-bot challenge (HTTP {$response->status()}) on request #{$this->remoteRequests}; " .
                "waiting {$waitSeconds}s before retry " . ($attempt + 1) . "/{$maxRetries}."
            );

            Sleep::for($waitSeconds)->seconds();
        }
    }

    /**
     * Stops hammering Ferrovias when it keeps failing in ways that are not the
     * recognized challenge (new challenge wording, timeouts, dropped connections).
     *
     * @throws RemoteBlockedError
     */
    private function registerConsecutiveRemoteError(): void
    {
        $this->consecutiveRemoteErrors++;

        if ($this->consecutiveRemoteErrors >= self::MAX_CONSECUTIVE_REMOTE_ERRORS) {
            throw new RemoteBlockedError(
                "{$this->consecutiveRemoteErrors} consecutive remote queries failed; " .
                'Ferrovias looks unavailable or is blocking the updater.'
            );
        }
    }

    private function throttleRemoteRequest(): void
    {
        $this->remoteRequests++;

        if ($this->lastRemoteRequestAt === null) {
            return;
        }

        $delayMilliseconds = $this->envInt('SCHEDULE_REQUEST_DELAY_MS', self::DEFAULT_REQUEST_DELAY_MS);
        $jitterMilliseconds = $this->envInt('SCHEDULE_REQUEST_JITTER_MS', self::DEFAULT_REQUEST_JITTER_MS);

        if ($jitterMilliseconds > 0) {
            $delayMilliseconds += random_int(0, $jitterMilliseconds);
        }

        $elapsedMilliseconds = (int) ((microtime(true) - $this->lastRemoteRequestAt) * 1000);

        if ($delayMilliseconds > $elapsedMilliseconds) {
            Sleep::for($delayMilliseconds - $elapsedMilliseconds)->milliseconds();
        }
    }

    private function isBotChallenge(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        // The challenge page has been served with 403, but match its markers on any
        // status so a 200/503 variant is not mistaken for a broken schedule table.
        $body = $response->body();

        foreach (self::BOT_CHALLENGE_MARKERS as $marker) {
            if (stripos($body, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    private function envInt(string $key, int $default): int
    {
        $value = env($key);

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max(0, (int) $value);
    }

    private function shouldRetry(\Exception $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException && $exception->response) {
            // Challenges are backed off by sendRemoteRequest(); retrying them here
            // would only triple the requests sent to an already blocking host.
            return $exception->response->serverError()
                && !$this->isBotChallenge($exception->response);
        }

        return false;
    }

    private function scheduleSegmentQueryValue(string $scheduleSegment): string
    {
        if (!isset(self::SCHEDULE_SEGMENT_QUERY_VALUES[$scheduleSegment])) {
            throw new \InvalidArgumentException("Unknown schedule segment '{$scheduleSegment}'.");
        }

        return self::SCHEDULE_SEGMENT_QUERY_VALUES[$scheduleSegment];
    }

    private function stationQueryName(string $stationName): string
    {
        return self::STATION_QUERY_ALIASES[$stationName] ?? $stationName;
    }

    private function stationIdsByQueryName(): array
    {
        if ($this->stationIdsByQueryName) {
            return $this->stationIdsByQueryName;
        }

        foreach (($this->availabilityOptions['origin'] ?? []) as $stationId => $stationName) {
            $this->stationIdsByQueryName[$this->normalizeStationKey($stationName)] = (string) $stationId;
            $this->stationIdsByQueryName[$this->normalizeStationKey($this->stationQueryName($stationName))] = (string) $stationId;
        }

        return $this->stationIdsByQueryName;
    }

    private function normalizeStationKey(string $stationName): string
    {
        $stationName = html_entity_decode($stationName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stationName = preg_replace('/\s+/', ' ', trim($stationName)) ?: '';

        $stationName = strtr($stationName, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
        $transliteratedStationName = \function_exists('iconv')
            ? \iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $stationName)
            : false;

        if ($transliteratedStationName !== false) {
            $stationName = $transliteratedStationName;
        }

        $stationName = strtolower($stationName);
        $stationName = preg_replace('/[^a-z0-9]+/', ' ', $stationName) ?: '';

        return trim(preg_replace('/\s+/', ' ', $stationName) ?: '');
    }

    private function normalizeTime(string $time): string
    {
        $time = trim($time);

        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches) !== 1) {
            return $time;
        }

        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }

    private function timeToMinutes(string $time): int
    {
        return $this->parseTimeToMinutes($time) ?? 0;
    }

    private function parseTimeToMinutes(string $time): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $this->normalizeTime($time), $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    private function isReasonableTravelDuration(string $departure, string $arrival): bool
    {
        $departureMinutes = $this->parseTimeToMinutes($departure);
        $arrivalMinutes = $this->parseTimeToMinutes($arrival);

        if ($departureMinutes === null || $arrivalMinutes === null) {
            return false;
        }

        if ($arrivalMinutes < $departureMinutes) {
            $arrivalMinutes += 24 * 60;
        }

        return ($arrivalMinutes - $departureMinutes) <= self::MAX_REASONABLE_TRAVEL_MINUTES;
    }

    private function debugQueries(): bool
    {
        return filter_var(env('DEBUG_SCHEDULE_QUERIES', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function failScheduleUpdate(
        ?Response $response,
        ?\Throwable $exception,
        ?string $sourceUrl,
    ): int {
        $diagnostics = $this->buildSourceDiagnostics(
            response: $response,
            exception: $exception,
            sourceUrl: $sourceUrl,
        );

        $this->error(
            'Remote schedule source changed or is unavailable; refusing to update from stale cached data.'
        );
        $this->line($diagnostics);

        return Command::FAILURE;
    }

    private function buildSourceDiagnostics(
        ?Response $response,
        ?\Throwable $exception,
        ?string $sourceUrl,
        ?string $expectedSelector = null,
    ): string {
        $lines = [
            'Remote source diagnostics:',
            '  - url=' . ($sourceUrl ?: '(not configured)'),
            '  - expected_selector=' . ($expectedSelector ?: self::SOURCE_SELECTOR),
        ];

        if ($response) {
            $lines[] = '  - effective_url=' . ($response->effectiveUri() ?: $sourceUrl);
            $lines[] = '  - status=' . $response->status();
            $lines[] = '  - content_type=' . ($response->header('Content-Type') ?: '(unknown)');
            $lines[] = '  - bytes=' . strlen($response->body());
            $lines[] = '  - body_preview=' . $this->previewBody($response->body());
        }

        if ($exception) {
            $lines[] = '  - exception=' . get_class($exception);
            $lines[] = '  - exception_message=' . $exception->getMessage();
        }

        return implode(PHP_EOL, $lines);
    }

    private function previewBody(string $body): string
    {
        $preview = preg_replace('/\s+/', ' ', trim(strip_tags($body)));

        if ($preview === '') {
            $preview = preg_replace('/\s+/', ' ', trim($body));
        }

        return substr($preview, 0, 500);
    }

}
