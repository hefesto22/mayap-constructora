<?php

declare(strict_types=1);

use App\Enums\AlertaMantenimiento;
use App\Exceptions\Maquinaria\MantenimientoPreventivoInvalidoException;
use App\Models\Maquina;
use App\Models\PlanMantenimiento;
use App\Services\Maquinaria\RegistrarCambioMantenimientoService;

/*
|--------------------------------------------------------------------------
| Golden tests del mantenimiento preventivo: la alerta se deriva del uso
| (horas / km / días — lo que llegue primero) y registrar el cambio
| resetea el contador dejando historial.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(RegistrarCambioMantenimientoService::class);
});

/**
 * @param array<string, mixed> $maquina
 * @param array<string, mixed> $plan
 */
function planDeAceite(array $maquina = [], array $plan = []): PlanMantenimiento
{
    $maquinaModelo = Maquina::factory()->create([
        'horometro_actual'   => 0,
        'kilometraje_actual' => null,
        ...$maquina,
    ]);

    return PlanMantenimiento::factory()->create([
        'maquina_id'              => $maquinaModelo->id,
        'nombre'                  => 'CAMBIO DE ACEITE',
        'frecuencia_horas'        => 250,
        'fecha_ultimo_cambio'     => today(),
        'horometro_ultimo_cambio' => 0,
        ...$plan,
    ]);
}

// ─── Alerta por horas de horómetro ─────────────────────────────────────

test('la alerta escala con las horas trabajadas desde el último cambio', function (float $horometro, AlertaMantenimiento $esperado): void {
    $plan = planDeAceite(maquina: ['horometro_actual' => $horometro]);

    expect($plan->estadoAlerta())->toBe($esperado);
})->with([
    'poco uso: al día'       => [100, AlertaMantenimiento::AlDia],
    '89%: todavía al día'    => [222, AlertaMantenimiento::AlDia],
    '92%: próximo'           => [230, AlertaMantenimiento::Proximo],
    'justo el 100%: vencido' => [250, AlertaMantenimiento::Vencido],
    'pasado: vencido'        => [400, AlertaMantenimiento::Vencido],
]);

// ─── Alerta por tiempo ─────────────────────────────────────────────────

test('la alerta escala con los días desde el último cambio', function (int $diasAtras, AlertaMantenimiento $esperado): void {
    $plan = planDeAceite(plan: [
        'frecuencia_horas'    => null,
        'frecuencia_dias'     => 90,
        'fecha_ultimo_cambio' => today()->subDays($diasAtras),
    ]);

    expect($plan->estadoAlerta())->toBe($esperado);
})->with([
    'hace un mes: al día' => [30, AlertaMantenimiento::AlDia],
    '81 días (90%)'       => [81, AlertaMantenimiento::Proximo],
    '95 días: vencido'    => [95, AlertaMantenimiento::Vencido],
]);

// ─── Alerta por kilómetros ─────────────────────────────────────────────

test('la alerta por km usa el kilometraje manual de la máquina', function (): void {
    $plan = planDeAceite(
        maquina: ['kilometraje_actual' => 4600],
        plan: [
            'frecuencia_horas' => null,
            'frecuencia_km'    => 5000,
            'km_ultimo_cambio' => 0,
        ],
    );

    expect($plan->estadoAlerta())->toBe(AlertaMantenimiento::Proximo);
});

test('sin kilometraje capturado el frente de km se ignora', function (): void {
    $plan = planDeAceite(plan: [
        'frecuencia_horas' => null,
        'frecuencia_km'    => 5000,
        'frecuencia_dias'  => 90,
        'km_ultimo_cambio' => 0,
    ]);

    // La máquina no tiene km: solo cuenta el frente de días (hoy = al día).
    expect($plan->ratiosDeUso())->not->toHaveKey('km')
        ->and($plan->estadoAlerta())->toBe(AlertaMantenimiento::AlDia);
});

// ─── Lo que llegue primero manda ───────────────────────────────────────

test('con varios frentes gana el peor', function (): void {
    $plan = planDeAceite(
        maquina: ['horometro_actual' => 50],
        plan: [
            'frecuencia_dias'     => 90,
            'fecha_ultimo_cambio' => today()->subDays(120),
        ],
    );

    // Por horas va al día (50/250), pero por tiempo ya venció.
    expect($plan->estadoAlerta())->toBe(AlertaMantenimiento::Vencido);
});

// ─── Registrar el cambio ───────────────────────────────────────────────

test('registrar el cambio resetea el contador y deja historial', function (): void {
    $plan = planDeAceite(maquina: ['horometro_actual' => 300]);
    expect($plan->estadoAlerta())->toBe(AlertaMantenimiento::Vencido);

    $plan->forceFill(['ultimo_aviso_estado' => 'vencido'])->save();

    $cambio = $this->service->registrar(
        plan: $plan,
        fecha: today()->toDateString(),
        horometro: '300',
        notas: 'ACEITE 15W40 + FILTRO',
    );

    $plan->refresh()->load('maquina');

    expect($plan->estadoAlerta())->toBe(AlertaMantenimiento::AlDia)
        ->and($plan->horometro_ultimo_cambio)->toBe('300.00')
        ->and($plan->fecha_ultimo_cambio->isToday())->toBeTrue()
        ->and($plan->ultimo_aviso_estado)->toBeNull()
        ->and($cambio->notas)->toBe('ACEITE 15W40 + FILTRO');

    $this->assertDatabaseHas('cambios_mantenimiento', [
        'plan_mantenimiento_id' => $plan->id,
        'horometro'             => '300.00',
    ]);

    $this->assertDatabaseHas('activity_log', [
        'log_name'   => 'maquinaria',
        'event'      => 'cambio_mantenimiento',
        'subject_id' => $plan->id,
    ]);
});

test('una lectura de km más reciente también actualiza la máquina', function (): void {
    $plan = planDeAceite(
        maquina: ['kilometraje_actual' => 1000],
        plan: ['frecuencia_km' => 5000, 'km_ultimo_cambio' => 0],
    );

    $this->service->registrar(
        plan: $plan,
        fecha: today()->toDateString(),
        kilometraje: '1500',
    );

    expect($plan->maquina->refresh()->kilometraje_actual)->toBe('1500.00')
        ->and($plan->refresh()->km_ultimo_cambio)->toBe('1500.00');
});

test('un cambio con fecha futura es rechazado', function (): void {
    $plan = planDeAceite();

    expect(fn () => $this->service->registrar($plan, today()->addDay()->toDateString()))
        ->toThrow(MantenimientoPreventivoInvalidoException::class);
});

test('no se registran cambios sobre un plan inactivo', function (): void {
    $plan = planDeAceite(plan: ['activo' => false]);

    expect(fn () => $this->service->registrar($plan, today()->toDateString()))
        ->toThrow(MantenimientoPreventivoInvalidoException::class);
});
