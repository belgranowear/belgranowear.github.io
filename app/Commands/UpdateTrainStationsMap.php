<?php

namespace App\Commands;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use LaravelZero\Framework\Commands\Command;

class UpdateTrainStationsMap extends Command
{

    const CACHE_FILENAME = 'train_stations.json';

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'app:update-train-stations-map';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Updates the map of Belgrano Norte\'s network managed by Ferrovias.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $operator = env('OVERPASS_FILTER_OPERATOR', 'Ferrovías');

        $response = Http::asForm()->post(
            url:    env('OVERPASS_API_INTERPRETER_URL'),
            data:   [
                'data' => "
                    [out:json];

                    nwr[operator=\"{$operator}\"];

                    out center;
                "
            ]
        );

        if (!$response->successful()) {
            $this->error("Something went wrong: {$response->body()}");

            return Command::FAILURE;
        }

        Storage::put(
            path:       self::CACHE_FILENAME,
            contents:   json_encode(
                value: $response->json(),
                flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
            )
        );

        $this->info('Success updating train stations map.');

        return Command::SUCCESS;
    }

}
