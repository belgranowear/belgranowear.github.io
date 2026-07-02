<?php

namespace App\Commands;

use App\Exceptions\InvalidOriginDestinationException;
use App\Exceptions\RemoteError;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

    private array $availabilityOptions = [];

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
     * query
     *
     * @param  array $query
     * 
     * @throws RemoteError
     * 
     * @return array
     */
    private function query(array $query): array {
        if ($this->debugQueries()) {
            $this->comment('Q = ' . json_encode($query));
        }

        try {
            $response = Http::retry(
                times: 3,
                sleepMilliseconds: 5 * 1000,
                when: fn (\Exception $exception) => $this->shouldRetry($exception),
                throw: false,
            )->get(
                url:    env('RESULTS_FORM_URL'),
                query:  $query
            );
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
                "[{$response->status()} <- " . json_encode($query) . PHP_EOL .
                $this->buildSourceDiagnostics(
                    response: $response,
                    exception: null,
                    sourceUrl: env('RESULTS_FORM_URL'),
                )
            );
        }

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

        $schedule = [];

        foreach ($table->find('tr') as $tr) {
            $cells = $tr->find('td');

            if (count($cells) < 3) {
                continue;
            }

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

            $schedule[] = [$departure, $arrival];
        }

        usort(
            $schedule,
            fn (array $left, array $right) => $this->timeToMinutes($left[0]) <=> $this->timeToMinutes($right[0])
        );

        if ($this->debugQueries()) {
            $this->comment('R = ' . json_encode($schedule));
        }

        if (empty($schedule)) {
            throw new RemoteError(
                __METHOD__ . ': couldn\'t find any usable schedule rows for query: ' . json_encode($query) .
                PHP_EOL .
                $this->buildSourceDiagnostics(
                    response: $response,
                    exception: null,
                    sourceUrl: env('RESULTS_FORM_URL'),
                    expectedSelector: self::RESULT_SELECTOR,
                )
            );
        }

        return $schedule;
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
        $sourceUrl = env('RESULTS_FORM_URL');

        [$baseFormResponse, $exception] = $this->fetchSource($sourceUrl);

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

        foreach (array_keys($this->availabilityOptions['scheduleSegment']) as $scheduleSegment) {
            $scheduleSegmentQueryValue = $this->scheduleSegmentQueryValue($scheduleSegment);

            foreach ($this->availabilityOptions['origin'] as $origin => $originName) {
                foreach ($this->availabilityOptions['destination'] as $destination => $destinationName) {
                    if ($origin == $destination) { continue; }

                    try {
                        $query = [
                            'origen'      => $this->stationQueryName($originName),
                            'destino'     => $this->stationQueryName($destinationName),
                            'tipo_dia'    => $scheduleSegmentQueryValue,
                            'hora_d'     => array_key_first($this->availabilityOptions['timeFrom']),
                            'hora_h'     => array_key_last($this->availabilityOptions['timeTo'])
                        ];
                        $query['hora_desde'] = $query['hora_d'];
                        $query['hora_hasta'] = $query['hora_h'];
                        unset($query['hora_d'], $query['hora_h']);

                        $filename = $this->buildFilename(
                            scheduleSegment: $scheduleSegment,
                            origin:          $origin,
                            destination:     $destination
                        );

                        $this->saveSchedule(
                            schedule: $this->query($query),
                            filename: $filename
                        );

                        $this->comment('  => ' . $filename);
                    } catch (RemoteError $remoteError) {
                        $failedQueries++;
                        $this->error(
                            "[Remote error] {$remoteError->getMessage()}" . PHP_EOL .
                            $remoteError->getTraceAsString()
                        );
                    } catch (InvalidOriginDestinationException $invalidOriginDestinationException) {
                        $failedQueries++;
                        $this->error(
                            "[BUG] {$invalidOriginDestinationException->getMessage()}" . PHP_EOL .
                            $invalidOriginDestinationException->getTraceAsString()
                        );
                    }
                }
            }
        }

        if ($failedQueries > 0) {
            $this->error("Schedule update finished with {$failedQueries} failed remote queries.");

            return Command::FAILURE;
        }

        $this->info('Success updating schedule.');

        return Command::SUCCESS;
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
                Http::retry(
                    times: 3,
                    sleepMilliseconds: 5 * 1000,
                    when: fn (\Exception $exception) => $this->shouldRetry($exception),
                    throw: false,
                )->get($sourceUrl),
                null,
            ];
        } catch (\Throwable $exception) {
            return [null, $exception];
        }
    }

    private function shouldRetry(\Exception $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException && $exception->response) {
            return $exception->response->serverError();
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
