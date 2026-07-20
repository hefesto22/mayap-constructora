<?php

declare(strict_types=1);

use App\Enums\FaseMantenimiento;
use App\Exceptions\Maquinaria\MantenimientoInvalidoException;
use App\Models\MantenimientoMaquina;
use App\Models\User;
use App\Services\Maquinaria\AvisarRepuestosService;
use App\Services\Maquinaria\MantenimientoService;
use App\Services\Maquinaria\RegistrarAvanceMantenimientoService;
use App\Support\Roles;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Golden tests de las fases del mantenimiento correctivo (decisión
| Mauricio 2026-07-20): diagnóstico → sin repuestos → compra de
| repuestos (con fecha estimada) → reparación → finalización, con
| bitácora de fecha y hora y campanita el día que deberían llegar
| los repuestos.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(RegistrarAvanceMantenimientoService::class);
});

test('un mantenimiento nuevo arranca en revisión/diagnóstico', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->create();

    expect($mantenimiento->refresh()->fase)->toBe(FaseMantenimiento::Diagnostico)
        ->and($mantenimiento->fecha_estimada_repuestos)->toBeNull();
});

test('registrar un avance escribe la bitácora con fecha, hora y usuario', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->create();
    $user = User::factory()->create();

    $entrada = $this->service->avanzar(
        mantenimiento: $mantenimiento,
        fase: FaseMantenimiento::Diagnostico,
        detalle: 'SE ENCONTRÓ FUGA EN LA BOMBA HIDRÁULICA',
        userId: $user->id,
    );

    expect($mantenimiento->refresh()->fase)->toBe(FaseMantenimiento::Diagnostico)
        ->and($mantenimiento->bitacoras()->count())->toBe(1)
        ->and($entrada->refresh()->detalle)->toBe('SE ENCONTRÓ FUGA EN LA BOMBA HIDRÁULICA')
        ->and($entrada->user_id)->toBe($user->id)
        ->and($entrada->created_at)->not->toBeNull();
});

test('la compra de repuestos exige fecha estimada de recepción', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->create();

    expect(fn () => $this->service->avanzar(
        mantenimiento: $mantenimiento,
        fase: FaseMantenimiento::CompraRepuestos,
        detalle: 'SE PIDIÓ LA BOMBA AL PROVEEDOR',
    ))->toThrow(MantenimientoInvalidoException::class, 'fecha estimada');
});

test('cambiar la fecha estimada reinicia el aviso de llegada', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->create();

    $this->service->avanzar(
        mantenimiento: $mantenimiento,
        fase: FaseMantenimiento::CompraRepuestos,
        detalle: 'SE PIDIÓ LA BOMBA AL PROVEEDOR',
        fechaEstimadaRepuestos: today()->addDays(5)->toDateString(),
    );

    // Simula que ya se avisó la llegada...
    $mantenimiento->refresh()->forceFill(['aviso_repuestos_at' => now()])->save();

    // ...y el proveedor la retrasó: nueva fecha → aviso rearmado.
    $this->service->avanzar(
        mantenimiento: $mantenimiento,
        fase: FaseMantenimiento::CompraRepuestos,
        detalle: 'EL PROVEEDOR RETRASÓ LA ENTREGA UNA SEMANA',
        fechaEstimadaRepuestos: today()->addDays(12)->toDateString(),
    );

    expect($mantenimiento->refresh()->aviso_repuestos_at)->toBeNull()
        ->and($mantenimiento->fecha_estimada_repuestos?->toDateString())
        ->toBe(today()->addDays(12)->toDateString())
        ->and($mantenimiento->bitacoras()->count())->toBe(2);
});

test('no se registran avances en un mantenimiento finalizado', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->finalizado()->create();

    expect(fn () => $this->service->avanzar(
        mantenimiento: $mantenimiento,
        fase: FaseMantenimiento::Reparacion,
        detalle: 'INTENTO TARDÍO',
    ))->toThrow(MantenimientoInvalidoException::class, 'finalizado');
});

test('finalizar deja el cierre anotado en la bitácora', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->create();

    app(MantenimientoService::class)->finalizar($mantenimiento);

    $ultima = $mantenimiento->bitacoras()->latest('id')->first();

    expect($ultima)->not->toBeNull()
        ->and($ultima->detalle)->toContain('finalizado');
});

// ─── Campanita "repuestos por llegar" ──────────────────────────────────

test('el día estimado avisa una sola vez y a los roles correctos', function (): void {
    Role::findOrCreate(Roles::GERENCIA, 'web');
    $gerente = User::factory()->create(['is_active' => true]);
    $gerente->assignRole(Roles::GERENCIA);

    $mantenimiento = MantenimientoMaquina::factory()->create();

    $this->service->avanzar(
        mantenimiento: $mantenimiento,
        fase: FaseMantenimiento::CompraRepuestos,
        detalle: 'REPUESTOS PEDIDOS',
        fechaEstimadaRepuestos: today()->toDateString(),
    );

    $avisador = app(AvisarRepuestosService::class);

    expect($avisador->avisar())->toBe(1)
        ->and($avisador->avisar())->toBe(0)
        ->and($gerente->notifications()->count())->toBe(1)
        ->and($mantenimiento->refresh()->aviso_repuestos_at)->not->toBeNull();
});

test('una fecha estimada futura no dispara la campanita', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->create();

    $this->service->avanzar(
        mantenimiento: $mantenimiento,
        fase: FaseMantenimiento::CompraRepuestos,
        detalle: 'REPUESTOS PEDIDOS',
        fechaEstimadaRepuestos: today()->addDays(4)->toDateString(),
    );

    expect(app(AvisarRepuestosService::class)->avisar())->toBe(0);
});

test('un mantenimiento en reparación ya no espera repuestos: sin aviso', function (): void {
    $mantenimiento = MantenimientoMaquina::factory()->create();

    $this->service->avanzar(
        mantenimiento: $mantenimiento,
        fase: FaseMantenimiento::CompraRepuestos,
        detalle: 'REPUESTOS PEDIDOS',
        fechaEstimadaRepuestos: today()->toDateString(),
    );

    // Llegaron antes de tiempo y ya está en reparación.
    $this->service->avanzar(
        mantenimiento: $mantenimiento,
        fase: FaseMantenimiento::Reparacion,
        detalle: 'REPUESTOS INSTALÁNDOSE',
    );

    expect(app(AvisarRepuestosService::class)->avisar())->toBe(0);
});
