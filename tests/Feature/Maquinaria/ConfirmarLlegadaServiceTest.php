<?php

declare(strict_types=1);

use App\Enums\EstadoMaquina;
use App\Exceptions\Maquinaria\AgendaInvalidaException;
use App\Models\AgendaMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Maquinaria\CalendarioMaquinariaService;
use App\Services\Maquinaria\ConfirmarLlegadaService;
use App\Support\Roles;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Confirmar llegada — el encargado marca que la máquina YA está en su obra.
|--------------------------------------------------------------------------
| Click en el evento azul del calendario → "Sí, ya llegó". Queda quién y
| a qué hora, el rol maquinaria recibe la campanita y el evento pasa a
| VIOLETA con la hora real. Ni futuro, ni dos veces, ni obras ajenas.
*/

beforeEach(function (): void {
    $this->servicio = app(ConfirmarLlegadaService::class);
});

test('GOLDEN: el encargado confirma la llegada de hoy → queda quién/cuándo, campanita a maquinaria y VIOLETA en el calendario', function (): void {
    Role::firstOrCreate(['name' => Roles::MAQUINARIA, 'guard_name' => 'web']);
    $jefe = User::factory()->create();
    $jefe->assignRole(Roles::MAQUINARIA);

    $encargado = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create(['nombre' => 'CASA RES. LOS PINOS']);
    $obra->encargados()->attach($encargado);

    $agendado = AgendaMaquina::factory()->create([
        'maquina_id'   => Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320D'])->id,
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);

    $confirmado = $this->servicio->confirmar($agendado, $encargado);

    expect($confirmado->llegada_confirmada_at)->not->toBeNull()
        ->and($confirmado->llegada_confirmada_por)->toBe($encargado->id)
        ->and($jefe->notifications()->count())->toBe(1)
        ->and(json_encode($jefe->notifications()->first()->data))->toContain('EXCAVADORA CAT 320D')
        ->toContain('CASA RES. LOS PINOS');

    // El evento pasa a VIOLETA (trabajando ahí) con la hora real.
    $eventos = app(CalendarioMaquinariaService::class)
        ->eventos(today()->toDateString(), today()->toDateString());
    expect($eventos[0]['title'])->toContain('llegó')
        ->and($eventos[0]['color'])->toBe('#7c3aed');
});

test('NI FUTURO NI DOS VECES: confirmar antes del día o repetir la confirmación se rechaza', function (): void {
    $encargado = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach($encargado);

    // Agendado para MAÑANA: todavía no se confirma.
    $futuro = AgendaMaquina::factory()->create([
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->addDay()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);

    expect(fn () => $this->servicio->confirmar($futuro, $encargado))
        ->toThrow(AgendaInvalidaException::class, 'se confirma ese día');

    // De hoy: la primera pasa, la segunda se rechaza (no es un toggle).
    $hoy = AgendaMaquina::factory()->create([
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);

    $this->servicio->confirmar($hoy, $encargado);

    expect(fn () => $this->servicio->confirmar($hoy->refresh(), $encargado))
        ->toThrow(AgendaInvalidaException::class, 'ya fue confirmada');
});

test('SOLO SU OBRA: un usuario ajeno no confirma; el rol maquinaria sí (respaldo)', function (): void {
    Role::firstOrCreate(['name' => Roles::MAQUINARIA, 'guard_name' => 'web']);

    $ajeno = User::factory()->create(); // ni encargado de la obra ni rol amplio
    $obra = Proyecto::factory()->enEjecucion()->create();

    $agendado = AgendaMaquina::factory()->create([
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);

    expect(fn () => $this->servicio->confirmar($agendado, $ajeno))
        ->toThrow(AgendaInvalidaException::class, 'encargado de ESA obra');

    $deMaquinaria = User::factory()->create();
    $deMaquinaria->assignRole(Roles::MAQUINARIA);

    expect($this->servicio->confirmar($agendado, $deMaquinaria)->llegada_confirmada_at)->not->toBeNull();
});

test('TRABAJANDO: confirmada la llegada, la máquina se muestra trabajando HOY en esa obra; el taller siempre gana', function (): void {
    $encargado = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA DEL DIA']);
    $obra->encargados()->attach($encargado);
    $maquina = Maquina::factory()->create();

    $agendado = AgendaMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);

    // Antes de confirmar: no está trabajando.
    expect($maquina->trabajandoHoy())->toBeFalse();

    $this->servicio->confirmar($agendado, $encargado);
    $maquina = $maquina->fresh();

    expect($maquina->trabajandoHoy())->toBeTrue()
        ->and($maquina->obraDondeTrabajaHoy())->toBe('OBRA DEL DIA');

    // El ciclo de vida REAL manda: en taller no se muestra "trabajando".
    $maquina->forceFill(['estado' => EstadoMaquina::Mantenimiento])->save();
    expect($maquina->fresh()->trabajandoHoy())->toBeFalse();
});

test('UNA OBRA A LA VEZ: mientras no se confirme la salida, la otra obra del día NO puede confirmar la llegada', function (): void {
    $encargadoA = User::factory()->create();
    $encargadoB = User::factory()->create();
    $obraA = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA MAÑANA']);
    $obraB = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA TARDE']);
    $obraA->encargados()->attach($encargadoA);
    $obraB->encargados()->attach($encargadoB);
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320D']);

    $enA = AgendaMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'proyecto_id'  => $obraA->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);
    $enB = AgendaMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'proyecto_id'  => $obraB->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '14:00:00',
    ]);

    // Llega a la obra A. Mientras siga ahí, B no puede "recibirla".
    $this->servicio->confirmar($enA, $encargadoA);

    expect(fn () => $this->servicio->confirmar($enB, $encargadoB))
        ->toThrow(AgendaInvalidaException::class, 'sigue trabajando en OBRA MAÑANA');

    // A confirma que terminó → la máquina queda libre y B ya puede.
    $this->servicio->confirmarSalida($enA->refresh(), $encargadoA);

    expect($this->servicio->confirmar($enB->refresh(), $encargadoB)->llegada_confirmada_at)->not->toBeNull()
        ->and($maquina->fresh()->obraDondeTrabajaHoy())->toBe('OBRA TARDE');
});

test('SALIDA: sin llegada no hay salida, no se repite, y al salir la máquina deja de mostrarse trabajando', function (): void {
    Role::firstOrCreate(['name' => Roles::MAQUINARIA, 'guard_name' => 'web']);
    $jefe = User::factory()->create();
    $jefe->assignRole(Roles::MAQUINARIA);

    $encargado = User::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();
    $obra->encargados()->attach($encargado);
    $maquina = Maquina::factory()->create();

    $agendado = AgendaMaquina::factory()->create([
        'maquina_id'   => $maquina->id,
        'proyecto_id'  => $obra->id,
        'fecha'        => today()->toDateString(),
        'hora_entrada' => '08:00:00',
    ]);

    // Sin llegada no hay salida.
    expect(fn () => $this->servicio->confirmarSalida($agendado, $encargado))
        ->toThrow(AgendaInvalidaException::class, 'nunca llegó');

    $this->servicio->confirmar($agendado, $encargado);
    expect($maquina->fresh()->trabajandoHoy())->toBeTrue();

    $cerrado = $this->servicio->confirmarSalida($agendado->refresh(), $encargado);

    // Las dos campanitas (llegada y salida) nacen en el mismo segundo:
    // el orden no es confiable — se busca en TODAS, no en la "primera".
    // JSON_UNESCAPED_UNICODE: sin él la tilde viaja escapada
    // ("termin\\u00f3") y el toContain jamás la encuentra.
    $campanitas = $jefe->notifications()->get()
        ->map(fn ($n): string => json_encode($n->data, JSON_UNESCAPED_UNICODE) ?: '')
        ->implode(' ');

    expect($cerrado->salida_confirmada_at)->not->toBeNull()
        ->and($cerrado->salida_confirmada_por)->toBe($encargado->id)
        ->and($maquina->fresh()->trabajandoHoy())->toBeFalse() // ya salió: libre
        ->and($jefe->notifications()->count())->toBe(2) // llegada + salida
        ->and($campanitas)->toContain('terminó');

    // La salida tampoco es un toggle.
    expect(fn () => $this->servicio->confirmarSalida($cerrado, $encargado))
        ->toThrow(AgendaInvalidaException::class, 'ya fue confirmada');

    // En el calendario el ciclo cerrado se lee "llegó → terminó" y
    // SIGUE violeta: el gris no existe — al registrar horas/litros el
    // parte VERDE reemplaza a este evento (decisión Mauricio 2026-07-16).
    $eventos = app(CalendarioMaquinariaService::class)
        ->eventos(today()->toDateString(), today()->toDateString());
    expect($eventos[0]['title'])->toContain('→')
        ->and($eventos[0]['color'])->toBe('#7c3aed');
});
