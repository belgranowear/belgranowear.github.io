<?php

namespace App\Commands;

use App\Exceptions\InvalidOriginDestinationException;
use App\Exceptions\RemoteError;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use DiDom\Document;

use LaravelZero\Framework\Commands\Command;

use Exception;

class UpdateSchedule extends Command
{

    const CACHE_FILENAME = 'schedule_%s_data.json';

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
        $this->comment('Q = ' . json_encode($query));

        $response = Http::retry(times: 3, sleepMilliseconds: 5 * 1000)->asForm()->post(
            url:    env('RESULTS_FORM_URL'),
            data:   $query
        );

        if (!$response->successful()) {
            $this->warn(
                __METHOD__ . ': couldn\'t load results for query: ' . json_encode($query)
            );

            throw new RemoteError(
                "[{$response->status()} <- " . json_encode($query) . PHP_EOL .
                $response->body()
            );
        }

        $document = new Document( $response->body() );

        $table = $document->first('.col_izq_ch');

        $schedule = [];

        foreach ($table->find('tr') as $tr) {
            $timeFrameKey = array_key_last($schedule);

            if ($timeFrameKey === null) {
                $timeFrameKey = 0;
            } else if (!empty($schedule[$timeFrameKey])) {
                $schedule[] = [];

                $timeFrameKey = array_key_last($schedule);
            }

            foreach ($tr->find('td') as $index => $td) {
                if ($td->classes()->contains('titulo_filas')) {
                    continue; // header
                }

                if ($index > 1) { break; } // we don't care about the rest of data

                $schedule[$timeFrameKey][] = trim(
                    explode(' ', $td->text())[0]
                ); // "08:06 hs" => "08:06"
            }
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

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $baseFormResponse = Http::retry(times: 3, sleepMilliseconds: 5 * 1000)->get( env('RESULTS_FORM_URL') );

        if (!$baseFormResponse->successful()) {
            $this->error("Something went wrong: {$baseFormResponse->body()}");

            return Command::FAILURE;
        }

        $document = new Document( $baseFormResponse->body() );

        $optionsContainer = $document->first('#viaja_H');

        if (!$optionsContainer) {
            $this->error("Couldn't retrieve availability options container.");

            return Command::FAILURE;
        }

        $this->loadAvailabilityOptions();

        foreach (array_keys($this->availabilityOptions['scheduleSegment']) as $scheduleSegment) {
            foreach (array_keys($this->availabilityOptions['origin']) as $origin) {
                foreach (array_keys($this->availabilityOptions['destination']) as $destination) {
                    if ($origin == $destination) { continue; }

                    try {
                        $query = [
                            'estacion_o' => $origin,
                            'estacion_d' => $destination,
                            'tipo_dia'   => $scheduleSegment,
                            'hora_d'     => array_key_first($this->availabilityOptions['timeFrom']),
                            'hora_h'     => array_key_last($this->availabilityOptions['timeTo'])
                        ];

                        $filename = $this->buildFilename(
                            scheduleSegment: $query['tipo_dia'],
                            origin:          $query['estacion_o'],
                            destination:     $query['estacion_d']
                        );

                        Storage::put(
                            path:       $filename,
                            contents:   json_encode(
                                value: $this->query($query),
                                flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
                            )
                        );

                        $this->comment('  => ' . $filename);

                        $query['estacion_o'] = $destination;
                        $query['estacion_d'] = $origin;

                        $filename = $this->buildFilename(
                            scheduleSegment: $query['tipo_dia'],
                            origin:          $query['estacion_o'],
                            destination:     $query['estacion_d']
                        );

                        Storage::put(
                            path:       $filename,
                            contents:   json_encode(
                                value: $this->query($query),
                                flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
                            )
                        );

                        $this->comment('  => ' . $filename);
                    } catch (RemoteError $remoteError) {
                        $this->error(
                            "[Remote error] {$remoteError->getMessage()}" . PHP_EOL .
                            $remoteError->getTraceAsString()
                        );
                    } catch (InvalidOriginDestinationException $invalidOriginDestinationException) {
                        $this->error(
                            "[BUG] {$invalidOriginDestinationException->getMessage()}" . PHP_EOL .
                            $invalidOriginDestinationException->getTraceAsString()
                        );
                    }
                }
            }
        }

        $this->info('Success updating schedule.');

        return Command::SUCCESS;
    }

}
