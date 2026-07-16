<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMaquina;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Services\Maquinaria\MantenimientoService;

/*
|--------------------------------------------------------------------------
| Avería con agenda futura — el calendario nunca miente.
|--------------------------------------------------------------------------
| Al enviar a mantenimiento: los agendados FUTUROS se transfieren a la
| sustituta o se cancelan. El del mismo día no se toca (esa jornada se
| resuelve con las horas que alcanzó a trabajar).
*/

beforeEach(function (): void {
    $this->servicio = app(MantenimientoService::class);
});

/**
 * @return array{0: Maquina, 1: Proyecto}
 */
function averiaAgendaBase(): array
{
    $maquina = Maquina::factory()->create(['nombre' => 'EXCAVADORA CAT 320', 'estado' => EstadoMaquina::Asignada]);
    $obra = Proyecto::factory()->enEjecucion()->create(['nombre' => 'LAS PALMAS']);

    AsignacionMaquina::factory()->create([
        'maquina_id'          => $maquina->id, 'proyecto_id' => $obra->id,
        'tarifa_hora_pactada' => '350.00',
        'fecha_inicio'        => today()->subDays(3)->toDateString(),
        'fecha_fin'           => null,
        'estado'              => EstadoAsignacion::Activa,
    ]);

    // Agendada HOY (día de la avería) y 3 días futuros.
    foreach ([0, 1, 2, 3] as $offset) {
        AgendaMaquina::factory()->create([
            'maquina_id'  => $maquina->id,
            'proyecto_id' => $obra->id,
            'fecha'       => today()->addDays($offset)->toDateString(),
        ]);
    }

    return [$maquina, $obra];
}

test('SIN sustituta: la avería cancela TODOS sus agendados desde ese día (el trabajo real vive en el parte)', function (): void {
    [$maquina, $obra] = averiaAgendaBase();

    $this->servicio->enviarAMantenimiento($maquina, 'SE QUEBRO EL BRAZO HIDRAULICO');

    // Hoy y los 3 futuros: ningún azul mentiroso junto al ámbar.
    expect(AgendaMaquina::where('maquina_id', $maquina->id)->count())->toBe(0);
});

test('CON sustituta: hereda los agendados futuros, excepto el día en que ya estaba comprometida a esa obra', function (): void {
    [$maquina, $obra] = averiaAgendaBase();
    $sustituta = Maquina::factory()->create(['nombre' => 'RETRO JD 310', 'estado' => EstadoMaquina::Disponible]);

    // La sustituta YA estaba agendada a esa obra pasado mañana.
    AgendaMaquina::factory()->create([
        'maquina_id'  => $sustituta->id,
        'proyecto_id' => $obra->id,
        'fecha'       => today()->addDays(2)->toDateString(),
    ]);

    $this->servicio->enviarAMantenimiento($maquina, 'FUGA DE ACEITE', sustituta: $sustituta);

    // Averiada: sin agendados — todos resueltos.
    expect(AgendaMaquina::where('maquina_id', $maquina->id)->count())->toBe(0);

    // Sustituta: el suyo (día 2) + transferidos hoy, día 1 y día 3 (el
    // día 2 chocaba y se canceló) = 4. Además quedó ASIGNADA a la obra.
    expect(AgendaMaquina::where('maquina_id', $sustituta->id)->count())->toBe(4)
        ->and(AsignacionMaquina::where('maquina_id', $sustituta->id)
            ->where('proyecto_id', $obra->id)
            ->where('estado', EstadoAsignacion::Activa->value)
            ->exists())->toBeTrue();
});
