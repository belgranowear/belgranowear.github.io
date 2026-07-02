<?php

namespace App\Commands;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use DiDom\Document;

use LaravelZero\Framework\Commands\Command;

class UpdateAvailabilityOptions extends Command
{

    const CACHE_FILENAME = 'availability_options.json';
    const SOURCE_SELECTOR = 'form[action*="horarios"]';
    const FRONTEND_STATION_LABELS = [
        'Boulogne' => 'Boulogne Sur Mer',
    ];
    const SCHEDULE_SEGMENTS = [
        '1' => 'Lunes a Viernes',
        '2' => 'Sábados',
        '3' => 'Domingos y Feriados',
    ];

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'app:update-availability-options';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Updates the availability options reference from Ferrovias\' official website';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $sourceUrl = env('MAIN_WEBSITE_URL');

        [$baseFormResponse, $exception] = $this->fetchSource($sourceUrl);

        if ($exception || !$baseFormResponse?->successful()) {
            return $this->failAvailabilityOptionsUpdate(
                response: $baseFormResponse,
                exception: $exception,
                sourceUrl: $sourceUrl,
            );
        }

        $document = new Document( $baseFormResponse->body() );

        $optionsContainer = $document->first(self::SOURCE_SELECTOR);

        if (!$optionsContainer) {
            return $this->failAvailabilityOptionsUpdate(
                response: $baseFormResponse,
                exception: null,
                sourceUrl: $sourceUrl,
            );
        }

        $origin = $optionsContainer->first('[name="origen"]');
        $timeFrom = $optionsContainer->first('[name="hora_desde"]');
        $timeTo = $optionsContainer->first('[name="hora_hasta"]');
        $missingFields = [];

        foreach ([
            'origin' => $origin,
            'timeFrom' => $timeFrom,
            'timeTo' => $timeTo,
        ] as $key => $element) {
            if (!$element) {
                $missingFields[] = $key;
            }
        }

        if ($missingFields) {
            return $this->failAvailabilityOptionsUpdate(
                response: $baseFormResponse,
                exception: new \RuntimeException(
                    'Missing fields inside the availability form: ' . implode(', ', $missingFields)
                ),
                sourceUrl: $sourceUrl,
            );
        }

        $stations = $this->numberedOptions($origin);
        $timeFromOptions = $this->timeOptions($timeFrom, skipLastMidnight: true);
        $timeToOptions = $this->timeOptions($timeTo);

        $cachedData = [
            'origin' => $stations,
            'destination' => $stations,
            'scheduleSegment' => self::SCHEDULE_SEGMENTS,
            'timeFrom' => $timeFromOptions,
            'timeTo' => $timeToOptions,
        ];

        if (count($stations) < 2 || empty($timeFromOptions) || empty($timeToOptions)) {
            return $this->failAvailabilityOptionsUpdate(
                response: $baseFormResponse,
                exception: new \RuntimeException(
                    'Could not parse enough station/time options from the new Ferrovías horarios form.'
                ),
                sourceUrl: $sourceUrl,
            );
        }

        Storage::put(
            path:       self::CACHE_FILENAME,
            contents:   json_encode(
                value: $cachedData,
                flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
            )
        );

        $this->comment(
            'Availability options counts: ' .
            collect($cachedData)
                ->map(fn (array $options, string $key) => "{$key}=" . count($options))
                ->implode(', ')
        );
        $this->info('Success updating availability options.');

        return Command::SUCCESS;
    }

    private function fetchSource(?string $sourceUrl): array
    {
        if (!$sourceUrl) {
            return [
                null,
                new \RuntimeException('MAIN_WEBSITE_URL is not configured.'),
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

    private function numberedOptions($select): array
    {
        $options = [];
        $index = 1;

        foreach ($select->find('option') as $option) {
            $value = trim($option->getAttribute('value') ?? '');

            if ($value === '') {
                continue;
            }

            $options[(string) $index] = $this->frontendStationLabel($value);
            $index++;
        }

        return $options;
    }

    private function frontendStationLabel(string $sourceLabel): string
    {
        return self::FRONTEND_STATION_LABELS[$sourceLabel] ?? $sourceLabel;
    }

    private function timeOptions($select, bool $skipLastMidnight = false): array
    {
        $options = [];

        foreach ($select->find('option') as $option) {
            $value = trim($option->getAttribute('value') ?? '');

            if ($value === '' || ($skipLastMidnight && $value === '24:00')) {
                continue;
            }

            $options[$value] = $value;
        }

        return $options;
    }

    private function failAvailabilityOptionsUpdate(
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
            'Remote availability options source changed or is unavailable; ' .
            'refusing to update from stale cached data.'
        );
        $this->line($diagnostics);

        return Command::FAILURE;
    }

    private function buildSourceDiagnostics(
        ?Response $response,
        ?\Throwable $exception,
        ?string $sourceUrl,
    ): string {
        $lines = [
            'Remote source diagnostics:',
            '  - url=' . ($sourceUrl ?: '(not configured)'),
            '  - expected_selector=' . self::SOURCE_SELECTOR,
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
