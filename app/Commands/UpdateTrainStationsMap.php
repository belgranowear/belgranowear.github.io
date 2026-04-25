<?php

namespace App\Commands;

use Illuminate\Support\Arr;
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

        $query = "
            [out:json];

            nwr[operator=\"{$operator}\"];

            out center;
        ";

        $endpoints = array_filter(array_unique([
            env('OVERPASS_API_INTERPRETER_URL'),
            'https://overpass.kumi.systems/api/interpreter',
            'https://z.overpass-api.de/api/interpreter',
        ]));

        foreach ($endpoints as $endpoint) {
            try {
                $response = Http::withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'belgranowear.github.io updater (https://github.com/belgranowear/belgranowear.github.io)',
                ])
                    ->timeout(60)
                    ->retry(times: 3, sleepMilliseconds: 5 * 1000, throw: false)
                    ->asForm()
                    ->post(
                        url: $endpoint,
                        data: ['data' => $query]
                    );
            } catch (\Throwable $exception) {
                $this->warn("Overpass endpoint {$endpoint} failed: {$exception->getMessage()}");

                continue;
            }

            if (! $response->successful()) {
                $this->warn("Overpass endpoint {$endpoint} returned HTTP {$response->status()}.");

                continue;
            }

            $contents = $response->json();

            if (! $contents) {
                $this->warn("No data was retrieved from the Overpass endpoint {$endpoint}.");

                continue;
            }

            Arr::forget($contents, 'osm3s.timestamp_osm_base');

            Storage::put(
                path: self::CACHE_FILENAME,
                contents: json_encode(
                    value: $contents,
                    flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
                )
            );

            $this->info('Success updating train stations map.');

            return Command::SUCCESS;
        }

        if (Storage::exists(self::CACHE_FILENAME)) {
            $this->warn('No Overpass endpoint succeeded; keeping the existing train stations map.');

            return Command::SUCCESS;
        }

        $this->error('No data was retrieved from any Overpass API endpoint, and no cached train stations map exists.');

        return Command::FAILURE;
    }
}
