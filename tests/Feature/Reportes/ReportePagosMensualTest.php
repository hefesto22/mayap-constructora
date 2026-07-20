<?php

declare(strict_types=1);

use App\Enums\TipoReporteFiscal;
use App\Models\Abono;
use App\Models\CuentaPorPagar;
use App\Models\Proveedor;
use App\Models\ReporteFiscal;
use App\Services\Reportes\GenerarReportePagosMensualService;
use App\Services\Reportes\PurgarFotosFacturasService;
use App\Services\Reportes\RenderizadorPdf;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Golden tests del reporte mensual de PAGOS a proveedores (decisión
| Mauricio 2026-07-20): cada mes muestra SOLO los depósitos de ese mes
| con sus comprobantes; una compra saldada aparece PAGADA en el mes en
| que se terminó de pagar, con el registro de en qué meses se abonó.
|--------------------------------------------------------------------------
| El renderizador real (Browsershot/Chromium) se sustituye por un fake
| que escribe un PDF dummy: aquí se prueba el ciclo, no Chrome.
*/

final class RenderizadorPdfPagosFake implements RenderizadorPdf
{
    public function guardar(string $html, string $rutaDestino): string
    {
        $dir = dirname($rutaDestino);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($rutaDestino, '%PDF-FAKE '.strlen($html));

        return $rutaDestino;
    }
}

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');

    $this->app->bind(RenderizadorPdf::class, RenderizadorPdfPagosFake::class);

    $this->service = app(GenerarReportePagosMensualService::class);
    $this->periodo = today()->subMonthNoOverflow()->startOfMonth();
});

/**
 * Cuenta por pagar de un proveedor con nombre conocido.
 *
 * @param array<string, mixed> $attrs
 */
function cxpDeProveedor(string $proveedor, array $attrs = []): CuentaPorPagar
{
    return CuentaPorPagar::factory()->create([
        'proveedor_id' => Proveedor::factory()->create(['nombre' => $proveedor])->id,
        ...$attrs,
    ]);
}

function htmlDelPeriodo(): string
{
    return test()->service->construirHtml(
        test()->periodo,
        test()->service->abonosDelPeriodo(test()->periodo),
        test()->service->cuentasSaldadasEn(test()->periodo),
    );
}

// ─── Depósitos del mes ─────────────────────────────────────────────────

test('el mes muestra solo sus depósitos, con el comprobante incrustado', function (): void {
    Storage::disk('public')->put('comprobantes/trf.webp', 'imagen-fake');

    $cuenta = cxpDeProveedor('FERRETERIA EL MARTILLO', [
        'monto_original' => 20000,
        'saldo'          => 15000,
        'estado'         => 'parcial',
    ]);

    // Depósito DENTRO del mes, con foto.
    Abono::factory()->create([
        'cuenta_por_pagar_id' => $cuenta->id,
        'monto'               => 5000,
        'fecha'               => $this->periodo->copy()->addDays(9),
        'metodo'              => 'TRANSFERENCIA',
        'referencia'          => 'TRF-778899',
        'foto_comprobante'    => 'comprobantes/trf.webp',
    ]);

    // Depósito de OTRO mes: no aparece en este reporte.
    Abono::factory()->create([
        'cuenta_por_pagar_id' => $cuenta->id,
        'monto'               => 1000,
        'fecha'               => today(),
        'referencia'          => 'TRF-DEL-MES-SIGUIENTE',
    ]);

    $html = htmlDelPeriodo();

    expect($html)->toContain('FERRETERIA EL MARTILLO')
        ->and($html)->toContain('TRF-778899')
        ->and($html)->toContain('5,000.00')
        ->and($html)->toContain('data:image/webp;base64,')
        ->and($html)->not->toContain('TRF-DEL-MES-SIGUIENTE');
});

// ─── Compras saldadas: el mes en que se terminó de pagar ───────────────

test('la compra saldada aparece PAGADA en el mes final con su historial de meses', function (): void {
    $cuenta = cxpDeProveedor('BLOQUERA SULA', [
        'monto_original' => 9000,
        'saldo'          => 0,
        'estado'         => 'pagada',
    ]);

    $mesUno = $this->periodo->copy()->subMonthNoOverflow();

    // Abonó en el mes 1 y terminó de pagar en el mes 2 (el del reporte).
    Abono::factory()->create([
        'cuenta_por_pagar_id' => $cuenta->id,
        'monto'               => 4000,
        'fecha'               => $mesUno->copy()->addDays(14),
    ]);
    Abono::factory()->create([
        'cuenta_por_pagar_id' => $cuenta->id,
        'monto'               => 5000,
        'fecha'               => $this->periodo->copy()->addDays(20),
    ]);

    $saldadas = $this->service->cuentasSaldadasEn($this->periodo);

    expect($saldadas->pluck('id')->all())->toBe([$cuenta->id]);

    $html = htmlDelPeriodo();

    expect($html)->toContain('PAGADA')
        ->and($html)->toContain(ucfirst($mesUno->translatedFormat('F Y')).' — L 4,000.00 (1 abono)')
        ->and($html)->toContain(ucfirst($this->periodo->translatedFormat('F Y')).' — L 5,000.00 (1 abono)');

    // En el mes 1 la cuenta NO estaba saldada: ahí solo se ve su depósito.
    expect($this->service->cuentasSaldadasEn($mesUno))->toBeEmpty();
});

test('una cuenta con saldo no aparece como saldada aunque haya abonado este mes', function (): void {
    $cuenta = cxpDeProveedor('CEMENTOS DEL NORTE', [
        'monto_original' => 10000,
        'saldo'          => 7000,
        'estado'         => 'parcial',
    ]);

    Abono::factory()->create([
        'cuenta_por_pagar_id' => $cuenta->id,
        'monto'               => 3000,
        'fecha'               => $this->periodo->copy()->addDays(3),
    ]);

    expect($this->service->cuentasSaldadasEn($this->periodo))->toBeEmpty()
        ->and($this->service->abonosDelPeriodo($this->periodo))->toHaveCount(1);
});

// ─── La fila del reporte y su ciclo ────────────────────────────────────

test('el reporte de pagos convive con el de facturas del mismo período', function (): void {
    ReporteFiscal::factory()->create(['periodo' => $this->periodo]);

    $reporte = $this->service->generar($this->periodo);

    expect($reporte->tipo)->toBe(TipoReporteFiscal::Pagos)
        ->and(ReporteFiscal::query()->count())->toBe(2);

    // Regenerar actualiza la MISMA fila, no crea otra.
    $this->service->generar($this->periodo);

    expect(ReporteFiscal::query()->count())->toBe(2);
});

test('la purga borra los comprobantes archivados y limpia los abonos', function (): void {
    Storage::disk('public')->put('comprobantes/uno.webp', 'foto-1');

    $cuenta = cxpDeProveedor('PINTURAS LEMPIRA');

    $abono = Abono::factory()->create([
        'cuenta_por_pagar_id' => $cuenta->id,
        'fecha'               => $this->periodo->copy()->addDays(5),
        'foto_comprobante'    => 'comprobantes/uno.webp',
    ]);

    // Comprobante subido DESPUÉS del reporte: no se toca.
    Storage::disk('public')->put('comprobantes/tarde.webp', 'foto-2');

    $reporte = $this->service->generar($this->periodo);

    expect($reporte->fotos_incluidas)->toBe(['comprobantes/uno.webp']);

    // Ya pasó el colchón de 7 días.
    ReporteFiscal::query()->update(['created_at' => now()->subDays(8)]);

    $purgados = app(PurgarFotosFacturasService::class)->purgar();

    expect($purgados)->toBe(1)
        ->and(Storage::disk('public')->exists('comprobantes/uno.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('comprobantes/tarde.webp'))->toBeTrue()
        ->and($abono->refresh()->foto_comprobante)->toBeNull()
        ->and($reporte->refresh()->fotos_purgadas_at)->not->toBeNull();
});
