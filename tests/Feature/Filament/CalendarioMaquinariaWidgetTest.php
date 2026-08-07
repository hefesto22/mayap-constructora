<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Filament\Widgets\CalendarioMaquinariaWidget;
use App\Models\AgendaMaquina;
use App\Models\AsignacionMaquina;
use App\Models\MantenimientoMaquina;
use App\Models\User;
use App\Support\Permisos;
use App\Support\Roles;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * El calendario es la pantalla donde se opera todo el módulo: cuando un
 * clic no abre nada tiene que decir POR QUÉ (decisión Mauricio
 * 2026-08-07). Antes había cuatro caminos que se tragaban el clic en
 * silencio y se leían como pantalla rota.
 */
beforeEach(function (): void {
    Role::firstOrCreate(['name' => Roles::RECEPCION, 'guard_name' => 'web']);
    Permission::findOrCreate(Permisos::REGISTRAR_JORNADA_MAQUINA, 'web');

    $this->recepcion = User::factory()->create(['is_active' => true]);
    $this->recepcion->assignRole(Roles::RECEPCION);
    $this->recepcion->givePermissionTo(Permisos::REGISTRAR_JORNADA_MAQUINA);

    // Sin rol ni permisos: el caso de "solo mira".
    $this->mirón = User::factory()->create(['is_active' => true]);
});

test('recepción pulsa un agendado y el calendario le dice de quién es el paso', function (): void {
    $agendado = AgendaMaquina::factory()->create(['fecha' => today()->toDateString()]);

    $this->actingAs($this->recepcion);

    Livewire::test(CalendarioMaquinariaWidget::class)
        ->call('onEventClick', ['id' => "agenda-{$agendado->id}"])
        ->assertNotified('Esta llegada la marca quien está en el sitio');
});

test('el bloque de un mantenimiento abierto explica la reparación', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->create();

    $this->actingAs($this->recepcion);

    Livewire::test(CalendarioMaquinariaWidget::class)
        ->call('onEventClick', ['id' => "mantenimiento-{$mantenimiento->id}"])
        ->assertNotified("{$mantenimiento->codigo} · {$mantenimiento->maquina->nombre}");
});

test('el bloque de un mantenimiento ya cerrado también responde', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->finalizado()->create();

    $this->actingAs($this->recepcion);

    Livewire::test(CalendarioMaquinariaWidget::class)
        ->call('onEventClick', ['id' => "mantenimiento-{$mantenimiento->id}"])
        ->assertNotified("{$mantenimiento->codigo} · {$mantenimiento->maquina->nombre}");
});

test('una asignación ya finalizada lo dice en vez de tragarse el clic', function (): void {
    $asignacion = AsignacionMaquina::factory()->create([
        'estado'    => EstadoAsignacion::Finalizada->value,
        'fecha_fin' => today()->toDateString(),
    ]);

    $this->actingAs($this->recepcion);

    Livewire::test(CalendarioMaquinariaWidget::class)
        ->call('onEventClick', ['id' => "asignacion-{$asignacion->id}"])
        ->assertNotified('Esta asignación ya se cerró');
});

test('quien no captura jornadas recibe el aviso de solo consulta', function (): void {
    $asignacion = AsignacionMaquina::factory()->create();

    $this->actingAs($this->mirón);

    Livewire::test(CalendarioMaquinariaWidget::class)
        ->call('onEventClick', ['id' => "asignacion-{$asignacion->id}"])
        ->assertNotified('Aquí solo se consulta');
});

test('un evento que ya no existe no revienta el calendario', function (): void {
    $this->actingAs($this->recepcion);

    Livewire::test(CalendarioMaquinariaWidget::class)
        ->call('onEventClick', ['id' => 'agenda-999999'])
        ->call('onEventClick', ['id' => 'asignacion-999999'])
        ->call('onEventClick', ['id' => 'mantenimiento-999999'])
        ->call('onEventClick', ['id' => ''])
        ->assertSuccessful();
});

test('el permiso de registrar jornada es administrable desde la pantalla de Roles', function (): void {
    // Quedó huérfano cuando se borró la pantalla "Captura del día": Shield
    // dejó de generarlo y no vivía en Permisos, así que no aparecía en
    // ninguna pestaña. Si alguien lo saca de PERSONALIZADOS, se vuelve
    // invisible otra vez y solo se podrá mover tocando el seeder.
    expect(Permisos::PERSONALIZADOS)->toHaveKey(Permisos::REGISTRAR_JORNADA_MAQUINA);

    expect(Permisos::PERSONALIZADOS_POR_MODULO['Maquinaria — Operación diaria'])
        ->toHaveKey(Permisos::REGISTRAR_JORNADA_MAQUINA);
});
