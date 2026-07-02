<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

beforeEach(function () {
    Storage::fake('local');
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
