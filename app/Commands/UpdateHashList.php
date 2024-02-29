<?php

namespace App\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Storage;

use LaravelZero\Framework\Commands\Command;

class UpdateHashList extends Command
{

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'app:update-hash-list';

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
        foreach (
            Arr::where(
                array:      Storage::files(),
                callback:   function ($filename) {
                    return Str::endsWith($filename, '.json');
                }
            ) as $filename
        ) {
            $checksumFilename = Str::replaceLast(
                search:  '.json',
                replace: '',
                subject: $filename
            ) . '_sum';

            $checksum = md5( Storage::get($filename) );

            Storage::put(
                path:       $checksumFilename,
                contents:   $checksum
            );

            $this->comment("Set checksum for \"{$checksumFilename}\" to {$checksum}.");
        }

        $this->info('Success updating the list of checksums.');

        return Command::SUCCESS;
    }

}
