<?php

declare(strict_types=1);

use App\Exceptions\Maquinaria\ParteInvalidoException;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Models\ParteTrabajo;
use App\Services\Maquinaria\RegistrarParteService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Reconciliación horómetro vs horas cobradas (2026-09-04)
|--------------------------------------------------------------------------
| El horómetro mide MOTOR ENCENDIDO; las horas del parte son PRODUCTIVAS.
| La diferencia es tiempo muerto y en construcción ronda el 30%. Antes había
| que elegir: cobrar el delta completo, o cobrar lo real y dejar el horómetro
| congelado. Ahora se guardan las dos magnitudes.
*/

beforeEach(function (): void {
    $this->maquina = Maquina::factory()->create([
        'horometro_actual' => '10.00',
        'horas_dia_renta'  => '8.00',
    ]);

    $this->asignacion = AsignacionMaquina::factory()
        ->for($this->maquina, 'maquina')
        ->create(['tarifa_hora_pactada' => '500.00']);
});

test('el caso de Mauricio: horómetro 10 a 22, se cobran 8, quedan 4 muertas', function (): void {
    $parte = app(RegistrarParteService::class)->registrarPorHorometro(
        asignacion: $this->asignacion,
        lecturaFinal: '22.00',
        horasCobradas: '8.00',
    );

    expect($parte->horas_motor)->toBe('12.00')
        ->and($parte->horas)->toBe('8.00')
        ->and($parte->horas_muertas)->toBe('4.00')
        // 4 de 12 = 33%, por debajo del 35% tolerado: no pide motivo.
        ->and($parte->porcentajeIdle())->toBe('33.33')
        ->and($parte->motivo_idle)->toBeNull()
        // Se cobran 8, no 12.
        ->and($parte->costo_cache)->toBe('4000.00')
        // Y el horómetro de la máquina SÍ avanza a la lectura real.
        ->and($this->maquina->refresh()->horometro_actual)->toBe('22.00');
});

test('sin horas cobradas se cobra el delta completo del horómetro', function (): void {
    $parte = app(RegistrarParteService::class)->registrarPorHorometro(
        asignacion: $this->asignacion,
        lecturaFinal: '18.00',
    );

    expect($parte->horas_motor)->toBe('8.00')
        ->and($parte->horas)->toBe('8.00')
        ->and($parte->horas_muertas)->toBe('0.00');
});

test('no se pueden cobrar más horas de las que corrió el motor', function (): void {
    app(RegistrarParteService::class)->registrarPorHorometro(
        asignacion: $this->asignacion,
        lecturaFinal: '18.00',   // 8 horas de motor
        horasCobradas: '10.00',  // pretende cobrar 10
    );
})->throws(ParteInvalidoException::class);

test('un tiempo muerto por encima de lo tolerado exige motivo', function (): void {
    app(RegistrarParteService::class)->registrarPorHorometro(
        asignacion: $this->asignacion,
        lecturaFinal: '30.00',  // 20 horas de motor
        horasCobradas: '5.00',  // 15 muertas = 75%
    );
})->throws(ParteInvalidoException::class);

test('con motivo, el tiempo muerto alto se acepta y queda escrito', function (): void {
    $parte = app(RegistrarParteService::class)->registrarPorHorometro(
        asignacion: $this->asignacion,
        lecturaFinal: '30.00',
        horasCobradas: '5.00',
        motivoIdle: '  SE LLOVIO TODA LA TARDE, MAQUINA ENCENDIDA ESPERANDO  ',
    );

    expect($parte->horas_muertas)->toBe('15.00')
        ->and($parte->porcentajeIdle())->toBe('75.00')
        ->and($parte->motivo_idle)->toBe('SE LLOVIO TODA LA TARDE, MAQUINA ENCENDIDA ESPERANDO');
});

test('un salto en el horómetro delata horas que nadie reportó', function (): void {
    // La máquina quedó en 10, pero el parte de hoy ABRE en 17: alguien la
    // usó 7 horas sin cargar el parte.
    $parte = app(RegistrarParteService::class)->registrarPorHorometro(
        asignacion: $this->asignacion,
        lecturaFinal: '25.00',
        lecturaInicial: '17.00',
    );

    expect($parte->salto_horometro)->toBe('7.00')
        ->and($parte->tieneUsoNoDeclarado())->toBeTrue()
        // El parte se registra igual: el uso ya ocurrió, solo queda asentado.
        ->and($parte->horas_motor)->toBe('8.00');
});

test('sin salto, el parte no marca uso no declarado', function (): void {
    $parte = app(RegistrarParteService::class)->registrarPorHorometro(
        asignacion: $this->asignacion,
        lecturaFinal: '18.00',
    );

    expect($parte->salto_horometro)->toBe('0.00')
        ->and($parte->tieneUsoNoDeclarado())->toBeFalse();
});

test('un parte manual no inventa horas de motor ni tiempo muerto', function (): void {
    $parte = app(RegistrarParteService::class)->registrarManual(
        asignacion: $this->asignacion,
        horas: '6.00',
    );

    expect($parte->horas_motor)->toBeNull()
        ->and($parte->horas_muertas)->toBe('0.00')
        ->and($parte->porcentajeIdle())->toBeNull();
});

test('un parte recién instanciado no revienta al leer el idle', function (): void {
    $parte = new ParteTrabajo;

    expect($parte->horas_muertas)->toBe('0.00')
        ->and($parte->porcentajeIdle())->toBeNull()
        ->and($parte->tieneUsoNoDeclarado())->toBeFalse();
});

test('el CHECK de la DB rechaza cobrar más que las horas de motor', function (): void {
    DB::table('partes_trabajo')->insert([
        'codigo'                => 'PT-TEST-1',
        'asignacion_maquina_id' => $this->asignacion->id,
        'fecha'                 => today()->toDateString(),
        'metodo_captura'        => 'horometro',
        'modalidad'             => 'horas',
        'horas'                 => '10.00',
        'horas_motor'           => '8.00',
        'horas_extra'           => '2.00',
        'tarifa_aplicada'       => '500.00',
        'costo_cache'           => '5000.00',
        'created_at'            => now(),
        'updated_at'            => now(),
    ]);
})->throws(QueryException::class);
