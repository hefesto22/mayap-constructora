<?php

declare(strict_types=1);

use App\Models\AgendaMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Maquinaria\AvisarLlegadasService;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Aviso de llegada — "tu máquina llega en menos de una hora".
|--------------------------------------------------------------------------
| El scheduler corre maquinaria:avisar-llegadas cada 10 minutos: los
| agendados de HOY cuya hora de llegada cae en la próxima hora avisan a
| los encargados de la obra (campanita) UNA sola vez. Lo que ya pasó no
| avisa: un aviso tardío solo hace ruido.
*/

beforeEach(function (): void {
    $this->servicio = app(AvisarLlegadasService::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('GOLDEN: la máquina llega en 50 minutos → campanita al encargado con hora AM/PM y el agendado queda marcado', function (): void {
    Carbon::setTestNow(today()->setTime(7, 10));

    $encargado = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create(['nombre' => 'CASA RES. LOS PINOS']);
    $obra->encargados()->attach($encargado);

    $agendado = AgendaMaquina::factory()->create([
        'maquina_id'   => Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320D'])->id,
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);

    expect($this->servicio->avisar())->toBe(1);

    $data = json_encode($encargado->notifications()->first()->data);

    expect($encargado->notifications()->count())->toBe(1)
        ->and($data)->toContain('EXCAVADORA CAT 320D')
        ->toContain('CASA RES. LOS PINOS')
        ->toContain('8:00 AM')
        ->and($agendado->refresh()->aviso_llegada_at)->not->toBeNull();
});

test('IDEMPOTENTE: la segunda pasada del scheduler NO repite el aviso', function (): void {
    Carbon::setTestNow(today()->setTime(7, 10));

    $encargado = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach($encargado);

    AgendaMaquina::factory()->create([
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);

    expect($this->servicio->avisar())->toBe(1)
        ->and($this->servicio->avisar())->toBe(0)
        ->and($encargado->notifications()->count())->toBe(1);
});

test('FUERA DE VENTANA: lo que llega en varias horas o mañana todavía NO avisa', function (): void {
    Carbon::setTestNow(today()->setTime(7, 10));

    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach($encargado = User::factory()->create());

    // Hoy pero a las 11:00 (faltan casi 4 horas) y mañana a las 8:00.
    AgendaMaquina::factory()->create([
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '11:00:00',
    ]);
    AgendaMaquina::factory()->create([
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->addDay()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);

    expect($this->servicio->avisar())->toBe(0)
        ->and($encargado->notifications()->count())->toBe(0);
});

test('YA PASÓ: una llegada que quedó atrás no avisa tarde (solo haría ruido)', function (): void {
    Carbon::setTestNow(today()->setTime(15, 0));

    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach($encargado = User::factory()->create());

    AgendaMaquina::factory()->create([
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);

    expect($this->servicio->avisar())->toBe(0)
        ->and($encargado->notifications()->count())->toBe(0);
});

test('COMANDO: maquinaria:avisar-llegadas corre el service y termina bien', function (): void {
    Carbon::setTestNow(today()->setTime(7, 10));

    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach($encargado = User::factory()->create());

    AgendaMaquina::factory()->create([
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);

    $this->artisan('maquinaria:avisar-llegadas')->assertSuccessful();

    expect($encargado->notifications()->count())->toBe(1);
});
