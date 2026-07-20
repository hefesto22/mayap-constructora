<?php

declare(strict_types=1);

use App\Models\CuentaPorPagar;
use App\Models\User;
use App\Services\Pagos\AvisarVencimientosPagosService;
use App\Support\Roles;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Golden tests de los avisos escalonados de PAGOS a proveedores: 7 días,
| 3 días, el día del vencimiento y vencidas — sin duplicar campanitas.
| Espejo de la cobranza, pero de lo que DEBEMOS.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(AvisarVencimientosPagosService::class);
});

function cuentaPorPagarQueVenceEn(int $dias): CuentaPorPagar
{
    return CuentaPorPagar::factory()->create([
        'monto_original'    => 10000,
        'saldo'             => 10000,
        'fecha_emision'     => today()->subDays(30),
        'fecha_vencimiento' => today()->addDays($dias),
    ]);
}

test('marca el escalón correcto según los días restantes', function (int $dias, int $escalonEsperado): void {
    $cuenta = cuentaPorPagarQueVenceEn($dias);

    $avisos = $this->service->avisar();

    expect($avisos)->toBe(1)
        ->and($cuenta->refresh()->ultimo_aviso_dias)->toBe($escalonEsperado);
})->with([
    'vence en 7 días'  => [7, 7],
    'vence en 5 días'  => [5, 7],
    'vence en 3 días'  => [3, 3],
    'vence en 1 día'   => [1, 3],
    'vence hoy'        => [0, 0],
    'venció ayer'      => [-1, -1],
    'venció hace días' => [-10, -1],
]);

test('una cuenta lejos del vencimiento no genera aviso', function (): void {
    cuentaPorPagarQueVenceEn(15);

    expect($this->service->avisar())->toBe(0);
});

test('correr dos veces el mismo día no duplica avisos', function (): void {
    $cuenta = cuentaPorPagarQueVenceEn(3);

    expect($this->service->avisar())->toBe(1)
        ->and($this->service->avisar())->toBe(0)
        ->and($cuenta->refresh()->ultimo_aviso_dias)->toBe(3);
});

test('el escalón avanza hacia el vencimiento sin retroceder', function (): void {
    $cuenta = cuentaPorPagarQueVenceEn(5);

    // Hoy: escalón 7.
    $this->service->avisar();
    expect($cuenta->refresh()->ultimo_aviso_dias)->toBe(7);

    // Cinco días después la cuenta VENCE hoy → escalón 0.
    $this->travelTo(today()->addDays(5));
    expect($this->service->avisar())->toBe(1)
        ->and($cuenta->refresh()->ultimo_aviso_dias)->toBe(0);

    // Al día siguiente ya venció → escalón -1, una sola vez.
    $this->travelTo(today()->addDay());
    expect($this->service->avisar())->toBe(1)
        ->and($cuenta->refresh()->ultimo_aviso_dias)->toBe(-1)
        ->and($this->service->avisar())->toBe(0);
});

test('una cuenta pagada sale del radar aunque esté vencida', function (): void {
    CuentaPorPagar::factory()->create([
        'monto_original'    => 10000,
        'saldo'             => 0,
        'estado'            => 'pagada',
        'fecha_emision'     => today()->subDays(30),
        'fecha_vencimiento' => today()->subDays(5),
    ]);

    expect($this->service->avisar())->toBe(0);
});

test('cambiar el vencimiento con escalón reiniciado rearma el ciclo', function (): void {
    $cuenta = cuentaPorPagarQueVenceEn(0);

    // Hoy avisa "vence HOY" (escalón 0)...
    expect($this->service->avisar())->toBe(1);

    // ...el proveedor dio prórroga: nueva fecha lejana + escalón NULL
    // (lo que hace AccionCambiarVencimiento). Sale del radar sin avisar.
    $cuenta->forceFill([
        'fecha_vencimiento' => today()->addDays(20),
        'ultimo_aviso_dias' => null,
    ])->save();

    expect($this->service->avisar())->toBe(0);

    // Y cuando la nueva fecha se acerca, el ciclo vuelve a empezar.
    $this->travelTo(today()->addDays(14));
    expect($this->service->avisar())->toBe(1)
        ->and($cuenta->refresh()->ultimo_aviso_dias)->toBe(7);
});

test('la campanita llega a gerencia y recepción, no al resto', function (): void {
    Role::findOrCreate(Roles::GERENCIA, 'web');
    Role::findOrCreate(Roles::RECEPCION, 'web');

    $gerente = User::factory()->create(['is_active' => true]);
    $gerente->assignRole(Roles::GERENCIA);

    $recepcion = User::factory()->create(['is_active' => true]);
    $recepcion->assignRole(Roles::RECEPCION);

    $otro = User::factory()->create(['is_active' => true]);

    cuentaPorPagarQueVenceEn(0);
    $this->service->avisar();

    expect($gerente->notifications()->count())->toBe(1)
        ->and($recepcion->notifications()->count())->toBe(1)
        ->and($otro->notifications()->count())->toBe(0);
});

// ─── Scopes de urgencia (alimentan las pestañas y el radar) ────────────

test('los scopes cortan la deuda por urgencia de pago', function (): void {
    $vencida = cuentaPorPagarQueVenceEn(-2);
    $porVencer = cuentaPorPagarQueVenceEn(5);
    $lejana = cuentaPorPagarQueVenceEn(30);

    CuentaPorPagar::factory()->create([
        'saldo'             => 0,
        'estado'            => 'pagada',
        'fecha_vencimiento' => today()->subDays(2),
    ]);

    expect(CuentaPorPagar::query()->vencidas()->pluck('id')->all())->toBe([$vencida->id])
        ->and(CuentaPorPagar::query()->porVencer()->pluck('id')->all())->toBe([$porVencer->id])
        ->and(CuentaPorPagar::query()->pendientes()->count())->toBe(3)
        ->and($lejana->exists)->toBeTrue();
});
