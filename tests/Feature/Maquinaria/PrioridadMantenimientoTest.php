<?php

declare(strict_types=1);

use App\Enums\PrioridadMantenimiento;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Models\MantenimientoMaquina;
use App\Models\User;
use App\Services\Maquinaria\CambiarPrioridadMantenimientoService;
use App\Support\Roles;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Prioridad de reparación (decisión Mauricio 2026-07-20): gerencia o
| recepción marcan cuál máquina es la más importante — campanita al
| taller y constancia en la bitácora.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(CambiarPrioridadMantenimientoService::class);
});

test('un mantenimiento nuevo nace con prioridad normal', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->create();

    expect($mantenimiento->refresh()->prioridad)->toBe(PrioridadMantenimiento::Normal);
});

test('cambiar la prioridad deja bitácora con quién y avisa a los roles', function (): void {
    Role::findOrCreate(Roles::MAQUINARIA, 'web');
    $taller = User::factory()->create(['is_active' => true]);
    $taller->assignRole(Roles::MAQUINARIA);

    $gerente = User::factory()->create();
    $mantenimiento = MantenimientoMaquina::factory()->create();

    $this->service->cambiar(
        mantenimiento: $mantenimiento,
        prioridad: PrioridadMantenimiento::Urgente,
        motivo: 'LA OBRA LAS FLORES ESTÁ PARADA',
        userId: $gerente->id,
    );

    $entrada = $mantenimiento->bitacoras()->latest('id')->first();

    expect($mantenimiento->refresh()->prioridad)->toBe(PrioridadMantenimiento::Urgente)
        ->and($entrada)->not->toBeNull()
        ->and((string) $entrada->detalle)->toContain('URGENTE')
        ->and((string) $entrada->detalle)->toContain('LAS FLORES')
        ->and($entrada->user_id)->toBe($gerente->id)
        ->and($taller->notifications()->count())->toBe(1);
});

test('repetir la misma prioridad no duplica bitácora ni campanitas', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->create();

    $this->service->cambiar($mantenimiento, PrioridadMantenimiento::Alta);
    $this->service->cambiar($mantenimiento, PrioridadMantenimiento::Alta);

    expect($mantenimiento->bitacoras()->count())->toBe(1);
});

test('no se cambia la prioridad de un mantenimiento finalizado', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->finalizado()->create();

    expect(fn () => $this->service->cambiar($mantenimiento, PrioridadMantenimiento::Urgente))
        ->toThrow(MantenimientoInvalidoException::class, 'finalizado');
});
