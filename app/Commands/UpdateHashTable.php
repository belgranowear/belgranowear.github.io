<?php

namespace App\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Storage;

use LaravelZero\Framework\Commands\Command;

class UpdateHashTable extends Command
{

    const CACHE_FILENAME = 'checksums.json';

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'app:update-hash-table';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Updates the MD5 checksum for each of the cached files.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $checksums = [];

        foreach (
            Arr::where(
                array:      Storage::files(),
                callback:   function ($filename) {
                    return
                        Str::endsWith($filename, '.json')
                        &&
                        $filename != self::CACHE_FILENAME;
                }
            ) as $filename
        ) {
            $key = Str::replaceLast(
                search:  '.json',
                replace: '',
                subject: $filename
            );

            $checksum = md5( Storage::get($filename) );

            $checksums[$key] = $checksum;

            $this->comment("Set checksum for \"{$key}\" to {$checksum}.");
        }

        Storage::put(
            path:       self::CACHE_FILENAME,
            contents:   json_encode(
                value: $checksums,
                flags: JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
            )
        );

        $this->info('Success updating the list of checksums.');

        return Command::SUCCESS;
    }

}
