<?php

declare(strict_types=1);

use App\Models\CuentaPorCobrar;
use App\Models\User;
use App\Services\Cobranza\AvisarVencimientosService;
use App\Support\Roles;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Golden tests de los avisos escalonados de cobranza: 7 días, 3 días,
| el día del vencimiento y vencidas — sin duplicar campanitas.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->service = app(AvisarVencimientosService::class);
});

function cuentaQueVenceEn(int $dias): CuentaPorCobrar
{
    return CuentaPorCobrar::factory()->create([
        'monto_original'    => 10000,
        'saldo'             => 10000,
        'fecha_emision'     => today()->subDays(30),
        'fecha_vencimiento' => today()->addDays($dias),
    ]);
}

test('marca el escalón correcto según los días restantes', function (int $dias, int $escalonEsperado): void {
    $cuenta = cuentaQueVenceEn($dias);

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
    cuentaQueVenceEn(15);

    expect($this->service->avisar())->toBe(0);
});

test('correr dos veces el mismo día no duplica avisos', function (): void {
    $cuenta = cuentaQueVenceEn(3);

    expect($this->service->avisar())->toBe(1)
        ->and($this->service->avisar())->toBe(0)
        ->and($cuenta->refresh()->ultimo_aviso_dias)->toBe(3);
});

test('el escalón avanza hacia el vencimiento sin retroceder', function (): void {
    $cuenta = cuentaQueVenceEn(5);

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
    CuentaPorCobrar::factory()->create([
        'monto_original'    => 10000,
        'saldo'             => 0,
        'estado'            => 'pagada',
        'fecha_emision'     => today()->subDays(30),
        'fecha_vencimiento' => today()->subDays(5),
    ]);

    expect($this->service->avisar())->toBe(0);
});

test('la campanita llega a los usuarios con rol de cobranza', function (): void {
    Role::findOrCreate(Roles::GERENCIA);

    $gerente = User::factory()->create(['is_active' => true]);
    $gerente->assignRole(Roles::GERENCIA);

    $sinRol = User::factory()->create(['is_active' => true]);

    cuentaQueVenceEn(0);

    $this->service->avisar();

    expect($gerente->notifications()->count())->toBe(1)
        ->and($sinRol->notifications()->count())->toBe(0);
});
