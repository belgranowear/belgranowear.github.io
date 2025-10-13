<?php

namespace App\Commands;

use App\Exceptions\InvalidYearException;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use LaravelZero\Framework\Commands\Command;

class UpdateHolidaysList extends Command
{

    const CACHE_FILENAME = 'holidays_%s.json';

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'app:update-holidays-list';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Updates the cached holidays list for the current and the next year from nolaborables.com.ar.';

    /**
     * buildFilename
     *
     * @param  int $year
     *
     * @throws InvalidYearException
     *
     * @return string
     */
    private function buildFilename(int $year): string {
        if (strlen($year) == 0 || $year <= 0) {
            throw new InvalidYearException('An invalid year was specified, a valid year is an integer greater than zero.');
        }

        return sprintf(self::CACHE_FILENAME, $year);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        foreach ([ date('Y'), (date('Y') + 1) ] as $year) {
            $response = Http::retry(times: 3, sleepMilliseconds: 5 * 1000)->get( env('HOLIDAYS_LIST_API_URL') . "/{$year}" );

            if (!$response->successful()) {
                $this->error("Something went wrong: " . $response->status() . " - " . $response->body());

                return Command::FAILURE;
            }

            $filename = $this->buildFilename($year);

            $holidays = $response->json();

            if (!$holidays) {
                $this->error("Couldn't retrieve the list of holidays for {$year}.");

                return Command::FAILURE;
            }

            // Transform the data to the old format
            $contents = array_map(function ($holiday) {
                return [
                    'motivo' => $holiday['nombre'],
                    'tipo' => $holiday['tipo'],
                    'info' => '', // No info available in the new API
                    'dia' => (int) substr($holiday['fecha'], 8, 2),
                    'mes' => (int) substr($holiday['fecha'], 5, 2),
                    'id' => str_replace(' ', '-', strtolower($holiday['nombre'])),
                ];
            }, $holidays);

            Storage::put(
                path: $filename,
                contents: json_encode(
                    value: $contents,
                    flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
                )
            );

            $this->comment("Stored holidays list for {$year} as \"{$filename}\".");
        }

        $this->info('Success updating the list of holidays.');

        return Command::SUCCESS;
    }

}
