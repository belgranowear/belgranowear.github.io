<?php

namespace App\Commands;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use DiDom\Document;

use LaravelZero\Framework\Commands\Command;

class UpdateAvailabilityOptions extends Command
{

    const CACHE_FILENAME = 'availability_options.json';

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
        $baseFormResponse = Http::get( env('MAIN_WEBSITE_URL') );

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

        $cachedData = [];

        foreach ([
            'origin'          => $optionsContainer->first('[name="estacion_o"]'),
            'destination'     => $optionsContainer->first('[name="estacion_d"]'),
            'scheduleSegment' => $optionsContainer->first('[name="tipo_dia"]'),
            'timeFrom'        => $optionsContainer->first('[name="hora_d"]'),
            'timeTo'          => $optionsContainer->first('[name="hora_h"]'),
        ] as $key => $element) {
            foreach ($element->find('option') as $option) {
                if (!isset($cachedData[$key])) {
                    $cachedData[$key] = [];
                }

                $cachedData[$key][
                    $option->getAttribute('value')
                ] = $option->text();
            }
        }

        Storage::put(
            path:       self::CACHE_FILENAME,
            contents:   json_encode($cachedData)
        );

        $this->info('Success updating availability options.');

        return Command::SUCCESS;
    }

}
