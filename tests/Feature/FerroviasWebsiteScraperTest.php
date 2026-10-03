<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;

function setUpdaterEnv(string $key, string $value): void
{
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

function newFerroviasScheduleFormHtml(?array $stations = null): string
{
    $stations ??= [ 'Retiro', 'Saldías' ];
    $stationOptions = collect($stations)
        ->map(fn (string $station) => "<option value=\"{$station}\">{$station}</option>")
        ->implode('');

    $html = <<<'HTML'
        <html>
            <body>
                <form action="/ferrovias/horarios" method="GET">
                    <select name="origen" id="fv-origen" required>
                        <option value="">Seleccioná origen...</option>
                        STATION_OPTIONS
                    </select>
                    <select name="destino" id="fv-destino" required disabled>
                        <option value="">Primero seleccioná origen...</option>
                    </select>
                    <select name="tipo_dia" required>
                        <option value="lv">Lunes a Viernes</option>
                        <option value="sab">Sábados</option>
                        <option value="dom">Domingos y Feriados</option>
                    </select>
                    <select name="hora_desde" id="fv-hora-desde" required>
                        <option value="00:00">00:00</option>
                        <option value="01:00">01:00</option>
                    </select>
                    <select name="hora_hasta" id="fv-hora-hasta" required>
                        <option value="00:00">00:00</option>
                        <option value="01:00">01:00</option>
                        <option value="24:00">24:00</option>
                    </select>
                </form>
            </body>
        </html>
    HTML;

    return str_replace('STATION_OPTIONS', $stationOptions, $html);
}

function newFerroviasStationResultHtml(array $rows): string
{
    $body = collect($rows)
        ->map(fn (array $row) => "<tr><td>{$row[0]}</td><td class='fv-highlight'>{$row[1]}</td><td>{$row[2]}</td></tr>")
        ->implode('');

    return <<<HTML
        <html>
            <body>
                <table class="fv-res-table">
                    <thead>
                        <tr><th>Tren N°</th><th>Sale de Retiro</th><th>Llega a Saldías</th></tr>
                    </thead>
                    <tbody>{$body}</tbody>
                </table>
            </body>
        </html>
    HTML;
}

function newFerroviasFormationResultHtml(array $rows): string
{
    $body = collect($rows)
        ->map(fn (array $row) => "<tr><td>{$row[0]}</td><td class='fv-highlight'>{$row[1]}</td></tr>")
        ->implode('');

    return <<<HTML
        <html>
            <body>
                <table class="fv-res-table">
                    <thead>
                        <tr><th>Estación</th><th>Horario</th></tr>
                    </thead>
                    <tbody>{$body}</tbody>
                </table>
            </body>
        </html>
    HTML;
}

function ferroviasBotChallengeHtml(): string
{
    return '<html><head><title>Checking your browser...</title></head><body>Javascript required</body></html>';
}

function setUpTwoStationAvailabilityOptions(): void
{
    Storage::put('availability_options.json', json_encode([
        'origin' => ['1' => 'Retiro', '2' => 'Saldías'],
        'destination' => ['1' => 'Retiro', '2' => 'Saldías'],
        'scheduleSegment' => ['1' => 'Lunes a Viernes'],
        'timeFrom' => ['00:00' => '00:00'],
        'timeTo' => ['24:00' => '24:00'],
    ]));
}

function twoStationScheduleResponse(array $query)
{
    if (!$query) {
        return Http::response(newFerroviasScheduleFormHtml());
    }

    if (isset($query['formacion'])) {
        return match ($query['formacion']) {
            '3001' => Http::response(newFerroviasFormationResultHtml([
                ['Retiro', '5:00'],
                ['Saldías', '5:04'],
            ])),
            '3002' => Http::response(newFerroviasFormationResultHtml([
                ['Saldías', '6:00'],
                ['Retiro', '6:04'],
            ])),
            default => Http::response('<html><body>Unexpected formation</body></html>', 500),
        };
    }

    return ($query['origen'] ?? null) === 'Retiro'
        ? Http::response(newFerroviasStationResultHtml([['3001', '5:00', '5:04']]))
        : Http::response(newFerroviasStationResultHtml([['3002', '6:00', '6:04']]));
}

beforeEach(function () {
    Storage::fake('local');
    Sleep::fake();

    setUpdaterEnv('SCHEDULE_REQUEST_DELAY_MS', '0');
    setUpdaterEnv('SCHEDULE_REQUEST_JITTER_MS', '0');
    setUpdaterEnv('SCHEDULE_BLOCK_BACKOFF_SECONDS', '0');
    setUpdaterEnv('SCHEDULE_BLOCK_MAX_RETRIES', '2');
});

it('extracts availability options from the new Ferrovias horarios form', function () {
    setUpdaterEnv('MAIN_WEBSITE_URL', 'https://ferrovias.test/horarios/');

    Http::fake([
        'https://ferrovias.test/horarios/' => Http::response(newFerroviasScheduleFormHtml()),
    ]);

    $exitCode = Artisan::call('app:update-availability-options');
    $options = json_decode(Storage::get('availability_options.json'), true);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($options['origin'])->toBe(['1' => 'Retiro', '2' => 'Saldías'])
        ->and($options['destination'])->toBe(['1' => 'Retiro', '2' => 'Saldías'])
        ->and($options['scheduleSegment'])->toBe([
            '1' => 'Lunes a Viernes',
            '2' => 'Sábados',
            '3' => 'Domingos y Feriados',
        ])
        ->and($options['timeFrom'])->toBe(['00:00' => '00:00', '01:00' => '01:00'])
        ->and($options['timeTo'])->toBe([
            '00:00' => '00:00',
            '01:00' => '01:00',
            '24:00' => '24:00',
        ]);
});

it('keeps frontend live-tracking-compatible display labels for Ferrovias stations', function () {
    setUpdaterEnv('MAIN_WEBSITE_URL', 'https://ferrovias.test/horarios/');

    Http::fake([
        'https://ferrovias.test/horarios/' => Http::response(newFerroviasScheduleFormHtml([
            'Retiro',
            'Saldías',
            'C. Universitaria',
            'A. del Valle',
            'M. Padilla',
            'Florida',
            'Munro',
            'Carapachay',
            'V. Adelina',
            'Boulogne',
        ])),
    ]);

    $exitCode = Artisan::call('app:update-availability-options');
    $options = json_decode(Storage::get('availability_options.json'), true);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($options['origin']['10'])->toBe('Boulogne Sur Mer')
        ->and($options['destination']['10'])->toBe('Boulogne Sur Mer');
});

it('scrapes station-to-station schedules from the new Ferrovias result table', function () {
    setUpdaterEnv('RESULTS_FORM_URL', 'https://ferrovias.test/horarios/');

    Storage::put('availability_options.json', json_encode([
        'origin' => ['1' => 'Retiro', '2' => 'Saldías'],
        'destination' => ['1' => 'Retiro', '2' => 'Saldías'],
        'scheduleSegment' => ['1' => 'Lunes a Viernes'],
        'timeFrom' => ['00:00' => '00:00'],
        'timeTo' => ['24:00' => '24:00'],
    ]));

    Http::fake(function (Request $request) {
        $query = [];
        parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?: '', $query);

        if (!$query) {
            return Http::response(newFerroviasScheduleFormHtml());
        }

        if (isset($query['formacion'])) {
            return match ($query['formacion']) {
                '3001' => Http::response(newFerroviasFormationResultHtml([
                    ['Retiro', '0:20'],
                    ['Saldías', '0:26'],
                ])),
                '3013' => Http::response(newFerroviasFormationResultHtml([
                    ['Retiro', '4:37'],
                    ['Saldías', '4:43'],
                ])),
                '3121' => Http::response(newFerroviasFormationResultHtml([
                    ['Retiro', '23:55'],
                    ['Saldías', '0:01'],
                ])),
                '3002' => Http::response(newFerroviasFormationResultHtml([
                    ['Saldías', '0:10'],
                    ['Retiro', '0:16'],
                ])),
                default => Http::response('<html><body>Unexpected formation: ' . json_encode($query) . '</body></html>', 500),
            };
        }

        if (($query['origen'] ?? null) === 'Retiro' && ($query['destino'] ?? null) === 'Saldías') {
            return Http::response(newFerroviasStationResultHtml([
                ['3013', '4:37', '4:43'],
                ['3001', '0:20', '0:26'],
                ['3121', '23:55', '0:01'],
                ['3122', '0:05', '23:58'],
            ]));
        }

        if (($query['origen'] ?? null) === 'Saldías' && ($query['destino'] ?? null) === 'Retiro') {
            return Http::response(newFerroviasStationResultHtml([
                ['3002', '0:10', '0:16'],
            ]));
        }

        return Http::response('<html><body>Unexpected query</body></html>', 500);
    });

    $exitCode = Artisan::call('app:update-schedule');

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and(json_decode(Storage::get('schedule_1.1.2_data.json'), true))->toBe([
            ['00:20', '00:26'],
            ['04:37', '04:43'],
            ['23:55', '00:01'],
        ])
        ->and(json_decode(Storage::get('schedule_1.2.1_data.json'), true))->toBe([
            ['00:10', '00:16'],
        ]);
});

it('discovers adjacent train numbers once and expands formation schedules to every station pair', function () {
    setUpdaterEnv('RESULTS_FORM_URL', 'https://ferrovias.test/horarios/');

    Storage::put('availability_options.json', json_encode([
        'origin' => ['1' => 'Retiro', '2' => 'Saldías', '3' => 'C. Universitaria'],
        'destination' => ['1' => 'Retiro', '2' => 'Saldías', '3' => 'C. Universitaria'],
        'scheduleSegment' => ['1' => 'Lunes a Viernes'],
        'timeFrom' => ['00:00' => '00:00'],
        'timeTo' => ['24:00' => '24:00'],
    ]));

    $directRetiroCiudadRequests = 0;
    $formationRequests = [];

    Http::fake(function (Request $request) use (&$directRetiroCiudadRequests, &$formationRequests) {
        $query = [];
        parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?: '', $query);

        if (!$query) {
            return Http::response(newFerroviasScheduleFormHtml([
                'Retiro',
                'Saldías',
                'C. Universitaria',
            ]));
        }

        if (isset($query['formacion'])) {
            $formationRequests[] = "{$query['tipo_dia_form']}:{$query['formacion']}";

            if ($query['formacion'] === '3013') {
                return Http::response(newFerroviasFormationResultHtml([
                    ['Retiro', '4:37'],
                    ['Saldías', '4:43'],
                    ['C. Universitaria', '4:49'],
                ]));
            }

            if ($query['formacion'] === '3002') {
                return Http::response(newFerroviasFormationResultHtml([
                    ['C. Universitaria', '4:59'],
                    ['Saldías', '5:05'],
                    ['Retiro', '5:12'],
                ]));
            }

            if ($query['formacion'] === '3140') {
                return Http::response(newFerroviasFormationResultHtml([
                    ['Retiro', '0:07'],
                    ['Saldías', '0:00'],
                    ['C. Universitaria', '23:54'],
                ]));
            }

            return Http::response('<html><body>Unexpected formation</body></html>', 500);
        }

        if (($query['origen'] ?? null) === 'Retiro' && ($query['destino'] ?? null) === 'C. Universitaria') {
            $directRetiroCiudadRequests++;

            return Http::response('<html><body>Non-adjacent direct queries should not be needed</body></html>', 500);
        }

        if (($query['origen'] ?? null) === 'Retiro' && ($query['destino'] ?? null) === 'Saldías') {
            return Http::response(newFerroviasStationResultHtml([
                ['3013', '4:37', '4:43'],
            ]));
        }

        if (($query['origen'] ?? null) === 'Saldías' && ($query['destino'] ?? null) === 'C. Universitaria') {
            return Http::response(newFerroviasStationResultHtml([
                ['3013', '4:43', '4:49'],
            ]));
        }

        if (($query['origen'] ?? null) === 'C. Universitaria' && ($query['destino'] ?? null) === 'Saldías') {
            return Http::response(newFerroviasStationResultHtml([
                ['3002', '4:59', '5:05'],
                ['3140', '23:54', '0:00'],
            ]));
        }

        if (($query['origen'] ?? null) === 'Saldías' && ($query['destino'] ?? null) === 'Retiro') {
            return Http::response(newFerroviasStationResultHtml([
                ['3002', '5:05', '5:12'],
                ['3140', '0:00', '0:07'],
            ]));
        }

        return Http::response('<html><body>Unexpected query: ' . json_encode($query) . '</body></html>', 500);
    });

    $exitCode = Artisan::call('app:update-schedule');

    sort($formationRequests);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($directRetiroCiudadRequests)->toBe(0)
        ->and($formationRequests)->toBe(['lv:3002', 'lv:3013', 'lv:3140'])
        ->and(json_decode(Storage::get('schedule_1.1.3_data.json'), true))->toBe([
            ['04:37', '04:49'],
        ])
        ->and(json_decode(Storage::get('schedule_1.3.1_data.json'), true))->toBe([
            ['04:59', '05:12'],
            ['23:54', '00:07'],
        ]);
});

it('queries Ferrovias with source station names even when public labels are frontend-compatible aliases', function () {
    setUpdaterEnv('RESULTS_FORM_URL', 'https://ferrovias.test/horarios/');

    Storage::put('availability_options.json', json_encode([
        'origin' => ['1' => 'Retiro', '10' => 'Boulogne Sur Mer'],
        'destination' => ['1' => 'Retiro', '10' => 'Boulogne Sur Mer'],
        'scheduleSegment' => ['1' => 'Lunes a Viernes'],
        'timeFrom' => ['00:00' => '00:00'],
        'timeTo' => ['24:00' => '24:00'],
    ]));

    Http::fake(function (Request $request) {
        $query = [];
        parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?: '', $query);

        if (!$query) {
            return Http::response(newFerroviasScheduleFormHtml([ 'Retiro', 'Boulogne' ]));
        }

        if (isset($query['formacion'])) {
            return match ($query['formacion']) {
                '3013' => Http::response(newFerroviasFormationResultHtml([
                    ['Retiro', '4:37'],
                    ['Boulogne', '5:14'],
                ])),
                '3002' => Http::response(newFerroviasFormationResultHtml([
                    ['Boulogne', '4:02'],
                    ['Retiro', '4:40'],
                ])),
                default => Http::response('<html><body>Unexpected formation: ' . json_encode($query) . '</body></html>', 500),
            };
        }

        if (($query['origen'] ?? null) === 'Retiro' && ($query['destino'] ?? null) === 'Boulogne') {
            return Http::response(newFerroviasStationResultHtml([
                ['3013', '4:37', '5:14'],
            ]));
        }

        if (($query['origen'] ?? null) === 'Boulogne' && ($query['destino'] ?? null) === 'Retiro') {
            return Http::response(newFerroviasStationResultHtml([
                ['3002', '4:02', '4:40'],
            ]));
        }

        return Http::response('<html><body>Unexpected query: ' . json_encode($query) . '</body></html>');
    });

    $exitCode = Artisan::call('app:update-schedule');

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and(json_decode(Storage::get('schedule_1.1.10_data.json'), true))->toBe([
            ['04:37', '05:14'],
        ])
        ->and(json_decode(Storage::get('schedule_1.10.1_data.json'), true))->toBe([
            ['04:02', '04:40'],
        ]);
});

it('fails loudly when the new Ferrovias result table cannot be found', function () {
    setUpdaterEnv('RESULTS_FORM_URL', 'https://ferrovias.test/horarios/');

    Storage::put('availability_options.json', json_encode([
        'origin' => ['1' => 'Retiro', '2' => 'Saldías'],
        'destination' => ['1' => 'Retiro', '2' => 'Saldías'],
        'scheduleSegment' => ['1' => 'Lunes a Viernes'],
        'timeFrom' => ['00:00' => '00:00'],
        'timeTo' => ['24:00' => '24:00'],
    ]));

    Http::fake(function (Request $request) {
        $query = parse_url((string) $request->url(), PHP_URL_QUERY);

        return Http::response($query ? '<html><body>No table here</body></html>' : newFerroviasScheduleFormHtml());
    });

    $exitCode = Artisan::call('app:update-schedule');
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('expected_selector=.fv-res-table')
        ->and($output)->toContain('Schedule update finished with 2 failed remote queries');
});

it('spaces Ferrovias requests and identifies the updater with a project user agent', function () {
    setUpdaterEnv('RESULTS_FORM_URL', 'https://ferrovias.test/horarios/');
    setUpdaterEnv('SCHEDULE_REQUEST_DELAY_MS', '60000');
    setUpTwoStationAvailabilityOptions();

    Http::fake(function (Request $request) {
        $query = [];
        parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?: '', $query);

        return twoStationScheduleResponse($query);
    });

    $exitCode = Artisan::call('app:update-schedule');

    // 1 form + 2 adjacent seeds + 2 formations; every request after the first waits.
    expect($exitCode)->toBe(Command::SUCCESS);
    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request) => str_starts_with(
        $request->header('User-Agent')[0] ?? '',
        'belgranowear-updater/'
    ));
    Sleep::assertSleptTimes(4);
});

it('backs off and recovers when Ferrovias answers with a temporary browser challenge', function () {
    setUpdaterEnv('RESULTS_FORM_URL', 'https://ferrovias.test/horarios/');
    setUpdaterEnv('SCHEDULE_BLOCK_BACKOFF_SECONDS', '90');
    setUpTwoStationAvailabilityOptions();

    $challenges = 0;

    Http::fake(function (Request $request) use (&$challenges) {
        $query = [];
        parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?: '', $query);

        if ($query && $challenges < 1) {
            $challenges++;

            return Http::response(ferroviasBotChallengeHtml(), 403);
        }

        return twoStationScheduleResponse($query);
    });

    $exitCode = Artisan::call('app:update-schedule');
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('waiting 90s before retry 1/2')
        ->and(json_decode(Storage::get('schedule_1.1.2_data.json'), true))->toBe([
            ['05:00', '05:04'],
        ]);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 90);
});

it('stops querying Ferrovias as soon as the browser challenge persists', function () {
    setUpdaterEnv('RESULTS_FORM_URL', 'https://ferrovias.test/horarios/');
    setUpdaterEnv('SCHEDULE_BLOCK_BACKOFF_SECONDS', '90');
    setUpTwoStationAvailabilityOptions();
    Storage::put('schedule_1.1.2_data.json', '[["04:00","04:04"]]');

    Http::fake(function (Request $request) {
        $query = parse_url((string) $request->url(), PHP_URL_QUERY);

        return $query
            ? Http::response(ferroviasBotChallengeHtml(), 403)
            : Http::response(newFerroviasScheduleFormHtml());
    });

    $exitCode = Artisan::call('app:update-schedule');
    $output = Artisan::output();

    // 1 form request + the first seed query tried 3 times; the second seed is never sent.
    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('after 4 remote requests: Ferrovias is blocking or not answering')
        ->and(Storage::get('schedule_1.1.2_data.json'))->toBe('[["04:00","04:04"]]');
    Http::assertSentCount(4);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 90);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 180);
});

it('stops after consecutive failures that do not look like the known challenge', function () {
    setUpdaterEnv('RESULTS_FORM_URL', 'https://ferrovias.test/horarios/');

    Storage::put('availability_options.json', json_encode([
        'origin' => ['1' => 'Retiro', '2' => 'Saldías', '3' => 'Ciudad Universitaria', '4' => 'Aristóbulo del Valle'],
        'destination' => ['1' => 'Retiro', '2' => 'Saldías', '3' => 'Ciudad Universitaria', '4' => 'Aristóbulo del Valle'],
        'scheduleSegment' => ['1' => 'Lunes a Viernes'],
        'timeFrom' => ['00:00' => '00:00'],
        'timeTo' => ['24:00' => '24:00'],
    ]));

    Http::fake(function (Request $request) {
        $query = parse_url((string) $request->url(), PHP_URL_QUERY);

        return $query
            ? Http::response('<html><body>Just a moment...</body></html>')
            : Http::response(newFerroviasScheduleFormHtml());
    });

    $exitCode = Artisan::call('app:update-schedule');
    $output = Artisan::output();

    // 6 adjacent seeds exist, but the run aborts after the 5th consecutive failure.
    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('5 consecutive remote queries failed');
    Http::assertSentCount(6);
});

it('refuses to expand formations whose stop times are not in travel order', function () {
    setUpdaterEnv('RESULTS_FORM_URL', 'https://ferrovias.test/horarios/');

    Storage::put('availability_options.json', json_encode([
        'origin' => ['1' => 'Retiro', '2' => 'Saldías', '3' => 'Ciudad Universitaria'],
        'destination' => ['1' => 'Retiro', '2' => 'Saldías', '3' => 'Ciudad Universitaria'],
        'scheduleSegment' => ['1' => 'Lunes a Viernes'],
        'timeFrom' => ['00:00' => '00:00'],
        'timeTo' => ['24:00' => '24:00'],
    ]));

    Http::fake(function (Request $request) {
        $query = [];
        parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?: '', $query);

        if (!$query) {
            return Http::response(newFerroviasScheduleFormHtml([ 'Retiro', 'Saldías', 'Ciudad Universitaria' ]));
        }

        if (isset($query['formacion'])) {
            // Sorted by clock time across midnight instead of by stop sequence.
            return Http::response(newFerroviasFormationResultHtml([
                ['Saldías', '0:01'],
                ['Ciudad Universitaria', '0:07'],
                ['Retiro', '23:55'],
            ]));
        }

        return Http::response(newFerroviasStationResultHtml([['3999', '23:55', '0:07']]));
    });

    $exitCode = Artisan::call('app:update-schedule');
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($output)->toContain('has stop times that are not in travel order')
        ->and(Storage::exists('schedule_1.2.3_data.json'))->toBeFalse();
});

it('warns about station pairs that no formation refreshed', function () {
    setUpdaterEnv('RESULTS_FORM_URL', 'https://ferrovias.test/horarios/');
    setUpTwoStationAvailabilityOptions();

    Http::fake(function (Request $request) {
        $query = [];
        parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?: '', $query);

        if (isset($query['formacion'])) {
            return Http::response(newFerroviasFormationResultHtml([
                ['Retiro', '5:00'],
                ['Saldías', '5:04'],
            ]));
        }

        if ($query) {
            return Http::response(newFerroviasStationResultHtml([['3001', '5:00', '5:04']]));
        }

        return Http::response(newFerroviasScheduleFormHtml());
    });

    $exitCode = Artisan::call('app:update-schedule');
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($output)->toContain('1 station pairs got no trains from any formation')
        ->and($output)->toContain('1.2.1')
        ->and($output)->toContain('::warning title=Schedule pairs not refreshed::');
});

it('summarizes checksum updates by default so action logs stay readable', function () {
    Storage::put('first.json', '{"ok":true}');
    Storage::put('second.json', '{"ok":false}');

    $exitCode = Artisan::call('app:update-hash-list');
    $output = Artisan::output();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and(Storage::exists('first_sum'))->toBeTrue()
        ->and(Storage::exists('second_sum'))->toBeTrue()
        ->and($output)->toContain('Updated 2 checksum files.')
        ->and($output)->not->toContain('Set checksum for');
});
