<?php

declare(strict_types=1);

use App\Filament\Resources\AgendaMaquina\AgendaMaquinaResource;
use App\Filament\Resources\SolicitudesMaquina\SolicitudMaquinaResource;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Services\Maquinaria\AgendarMaquinaService;

/*
|--------------------------------------------------------------------------
| Helpers del form "Agendar maquinaria" (Resource, sin UI).
|--------------------------------------------------------------------------
| La agenda es simple (llegada + obra): los helpers dan VISIBILIDAD para
| que quien agenda decida con criterio — compromisos del día junto al
| nombre de la máquina, y obra bloqueada si ya está agendada ahí ese día.
*/

test('COMPROMISOS: el label del select muestra las llegadas del día (un día) o el conteo (rango)', function (): void {
    $servicio = app(AgendarMaquinaService::class);
    $maquina = Maquina::factory()->create();
    $obraA = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA ALFA']);
    $obraB = Proyecto::factory()->enEjecucion()->create(['nombre' => 'OBRA BETA']);
    $fecha = today()->addDays(2)->toDateString();

    $servicio->agendar($maquina->id, $obraA->id, $fecha, horaEntrada: '08:00:00');
    $servicio->agendar($maquina->id, $obraB->id, $fecha, horaEntrada: '13:00:00');

    $detalle = AgendaMaquinaResource::compromisosPorAgenda([$fecha]);

    expect($detalle)->toHaveKey($maquina->id)
        ->and($detalle[$maquina->id])->toContain('llega 8:00 AM a OBRA ALFA')
        ->and($detalle[$maquina->id])->toContain('llega 1:00 PM a OBRA BETA');
});

test('HORAS 12H: las opciones de llegada van cada 30 min en AM/PM y guardan H:i', function (): void {
    $opciones = AgendaMaquinaResource::opcionesHoraLlegada();

    expect($opciones)->toHaveCount(48)
        ->and($opciones['00:00'])->toBe('12:00 AM')
        ->and($opciones['08:00'])->toBe('8:00 AM')
        ->and($opciones['13:30'])->toBe('1:30 PM')
        ->and($opciones['23:30'])->toBe('11:30 PM');
});

test('SOLICITUDES: la máquina ya agendada a la obra elegida se excluye del listado', function (): void {
    $servicio = app(AgendarMaquinaService::class);
    $maquina = Maquina::factory()->create();
    $obraA = Proyecto::factory()->enEjecucion()->create();
    $obraB = Proyecto::factory()->enEjecucion()->create();
    $fecha = today()->addDays(2)->toDateString();

    $servicio->agendar($maquina->id, $obraA->id, $fecha, horaEntrada: '08:00:00');

    $resource = SolicitudMaquinaResource::class;

    // En la obra A ya está: se excluye. En la obra B no: aparece normal.
    expect($resource::maquinasYaEnLaObra([$fecha], $obraA->id))->toContain($maquina->id)
        ->and($resource::maquinasYaEnLaObra([$fecha], $obraB->id))->toBe([]);
});

test('OBRA OCUPADA: con una máquina y un día, la obra ya agendada se bloquea en el select', function (): void {
    $servicio = app(AgendarMaquinaService::class);
    $maquina = Maquina::factory()->create();
    $obra = Proyecto::factory()->enEjecucion()->create();
    $fecha = today()->addDays(2)->toDateString();

    $servicio->agendar($maquina->id, $obra->id, $fecha, horaEntrada: '08:00:00');

    $ocupadas = AgendaMaquinaResource::obrasYaAgendadas([$fecha], [$maquina->id]);

    expect($ocupadas)->toHaveKey($obra->id)
        ->and($ocupadas[$obra->id]['bloquear'])->toBeTrue();

    // Con varias máquinas no aplica (el service decide al guardar).
    $otra = Maquina::factory()->create();
    expect(AgendaMaquinaResource::obrasYaAgendadas([$fecha], [$maquina->id, $otra->id]))->toBe([]);
});
