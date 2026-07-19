<?php

declare(strict_types=1);

use App\Models\Maquina;
use App\Models\PlanMantenimiento;
use App\Models\User;
use App\Services\Maquinaria\AvisarMantenimientosService;
use App\Services\Maquinaria\RegistrarCambioMantenimientoService;
use App\Support\Roles;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Golden tests de los avisos de mantenimiento preventivo: campanita al
| cruzar 90% (próximo) y 100% (vencido) del intervalo, sin duplicar y
| escalando solo hacia adelante. Registrar el cambio rearma el ciclo.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(AvisarMantenimientosService::class);
});

/**
 * @param array<string, mixed> $plan
 * @param array<string, mixed> $maquina
 */
function planConUso(float $horometro, array $plan = [], array $maquina = []): PlanMantenimiento
{
    $maquinaModelo = Maquina::factory()->create([
        'horometro_actual' => $horometro,
        ...$maquina,
    ]);

    return PlanMantenimiento::factory()->create([
        'maquina_id'              => $maquinaModelo->id,
        'nombre'                  => 'CAMBIO DE ACEITE',
        'frecuencia_horas'        => 100,
        'fecha_ultimo_cambio'     => today(),
        'horometro_ultimo_cambio' => 0,
        ...$plan,
    ]);
}

test('un plan al día no genera aviso', function (): void {
    planConUso(50);

    expect($this->service->avisar())->toBe(0);
});

test('avisa al cruzar el 90% y no repite en la siguiente pasada', function (): void {
    $plan = planConUso(92);

    expect($this->service->avisar())->toBe(1)
        ->and($plan->refresh()->ultimo_aviso_estado)->toBe('proximo')
        ->and($this->service->avisar())->toBe(0);
});

test('escala de próximo a vencido con un solo aviso nuevo', function (): void {
    $plan = planConUso(92);
    $this->service->avisar();

    $plan->maquina->forceFill(['horometro_actual' => 110])->save();

    expect($this->service->avisar())->toBe(1)
        ->and($plan->refresh()->ultimo_aviso_estado)->toBe('vencido')
        ->and($this->service->avisar())->toBe(0);
});

test('si saltó directo a vencido avisa UNA vez con el nivel real', function (): void {
    $plan = planConUso(300);

    expect($this->service->avisar())->toBe(1)
        ->and($plan->refresh()->ultimo_aviso_estado)->toBe('vencido');
});

test('planes inactivos y máquinas inactivas salen del radar', function (): void {
    planConUso(300, plan: ['activo' => false]);
    planConUso(300, maquina: ['activo' => false]);

    expect($this->service->avisar())->toBe(0);
});

test('registrar el cambio rearma el ciclo de avisos', function (): void {
    $plan = planConUso(300);
    $this->service->avisar();

    app(RegistrarCambioMantenimientoService::class)->registrar(
        plan: $plan->refresh(),
        fecha: today()->toDateString(),
        horometro: '300',
    );

    // Recién cambiado: nada que avisar.
    expect($this->service->avisar())->toBe(0);

    // Vuelve a acumular horas → vuelve a avisar.
    $plan->maquina->forceFill(['horometro_actual' => '395'])->save();

    expect($this->service->avisar())->toBe(1)
        ->and($plan->refresh()->ultimo_aviso_estado)->toBe('proximo');
});

test('la campanita llega a gerencia y maquinaria, no a otros', function (): void {
    Role::findOrCreate(Roles::GERENCIA);
    Role::findOrCreate(Roles::MAQUINARIA);

    $gerente = User::factory()->create(['is_active' => true]);
    $gerente->assignRole(Roles::GERENCIA);

    $taller = User::factory()->create(['is_active' => true]);
    $taller->assignRole(Roles::MAQUINARIA);

    $sinRol = User::factory()->create(['is_active' => true]);

    planConUso(300);

    $this->service->avisar();

    expect($gerente->notifications()->count())->toBe(1)
        ->and($taller->notifications()->count())->toBe(1)
        ->and($sinRol->notifications()->count())->toBe(0);
});
