<?php

declare(strict_types=1);

use App\Enums\EstadoCompra;
use App\Models\Compra;
use App\Models\ReporteFiscal;
use App\Models\User;
use App\Services\Reportes\GenerarReporteFiscalMensualService;
use App\Services\Reportes\PurgarFotosFacturasService;
use App\Services\Reportes\RenderizadorPdf;
use App\Support\Roles;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Golden tests del ciclo fiscal mensual: PDF con todas las compras del
| mes (anuladas marcadas) + fotos incrustadas, y purga de fotos 7 días
| después — solo las archivadas y solo con PDF sano.
|--------------------------------------------------------------------------
| El renderizador real (Browsershot/Chromium) se sustituye por un fake
| que escribe un PDF dummy: aquí se prueba el ciclo, no Chrome.
*/

final class RenderizadorPdfFake implements RenderizadorPdf
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

    $this->app->bind(RenderizadorPdf::class, RenderizadorPdfFake::class);

    $this->service = app(GenerarReporteFiscalMensualService::class);
    $this->periodo = today()->subMonthNoOverflow()->startOfMonth();
});

/**
 * @param array<string, mixed> $attrs
 */
function compraDelMes(array $attrs = []): Compra
{
    return Compra::factory()->create([
        'fecha' => test()->periodo->copy()->addDays(9),
        ...$attrs,
    ]);
}

test('el HTML incluye todas las compras del mes, marca anuladas y excluye otros meses', function (): void {
    $normal = compraDelMes(['numero_factura' => 'FAC-777']);
    $anulada = compraDelMes([
        'estado'           => EstadoCompra::Anulada->value,
        'motivo_anulacion' => 'ERROR DE CAPTURA',
        'anulada_at'       => now(),
    ]);
    $otroMes = Compra::factory()->create(['fecha' => test()->periodo->copy()->subDays(5)]);

    Storage::disk('public')->put('facturas/x/foto.webp', 'BYTES-DE-PRUEBA');
    $normal->forceFill(['fotos_factura' => ['facturas/x/foto.webp']])->save();

    $compras = $this->service->comprasDelPeriodo($this->periodo);
    $html = $this->service->construirHtml($this->periodo, $compras);

    expect($html)->toContain($normal->codigo)
        ->and($html)->toContain('FAC-777')
        ->and($html)->toContain($anulada->codigo)
        ->and($html)->toContain('ANULADA')
        ->and($html)->not->toContain($otroMes->codigo)
        ->and($html)->toContain(base64_encode('BYTES-DE-PRUEBA'));
});

test('generar deja el PDF sano y registra el período con sus conteos', function (): void {
    $compra = compraDelMes();
    Storage::disk('public')->put('facturas/x/a.webp', 'A');
    $compra->forceFill(['fotos_factura' => ['facturas/x/a.webp']])->save();
    compraDelMes();

    $reporte = $this->service->generar($this->periodo);

    expect($reporte->compras_count)->toBe(2)
        ->and($reporte->fotos_count)->toBe(1)
        ->and($reporte->fotos_incluidas)->toBe(['facturas/x/a.webp'])
        ->and($reporte->pdfSano())->toBeTrue()
        ->and($reporte->fotos_purgadas_at)->toBeNull();

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'compras',
        'event'    => 'reporte_fiscal_generado',
    ]);
});

test('regenerar el mismo mes actualiza la MISMA fila', function (): void {
    compraDelMes();

    $this->service->generar($this->periodo);
    compraDelMes();
    $this->service->generar($this->periodo);

    expect(ReporteFiscal::query()->count())->toBe(1)
        ->and(ReporteFiscal::query()->firstOrFail()->compras_count)->toBe(2);
});

test('la purga libera las fotos archivadas después del colchón de 7 días', function (): void {
    Role::findOrCreate(Roles::GERENCIA);
    $gerente = User::factory()->create(['is_active' => true]);
    $gerente->assignRole(Roles::GERENCIA);

    $compra = compraDelMes();
    Storage::disk('public')->put('facturas/x/a.webp', 'A');
    Storage::disk('public')->put('facturas/x/b.webp', 'B');
    $compra->forceFill(['fotos_factura' => ['facturas/x/a.webp', 'facturas/x/b.webp']])->save();

    $reporte = $this->service->generar($this->periodo);

    // Dentro del colchón: nada se toca.
    $this->travelTo(now()->addDays(3));
    expect(app(PurgarFotosFacturasService::class)->purgar())->toBe(0);

    // Cumplido el colchón: fotos fuera, compra limpia, aviso enviado.
    $this->travelTo(now()->addDays(5));
    expect(app(PurgarFotosFacturasService::class)->purgar())->toBe(1)
        ->and(Storage::disk('public')->exists('facturas/x/a.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('facturas/x/b.webp'))->toBeFalse()
        ->and($compra->refresh()->fotos_factura)->toBeNull()
        ->and($reporte->refresh()->fotos_purgadas_at)->not->toBeNull()
        ->and($gerente->notifications()->count())->toBeGreaterThan(0);

    // Idempotente: la segunda pasada no purga de nuevo.
    expect(app(PurgarFotosFacturasService::class)->purgar())->toBe(0);
});

test('sin PDF sano la purga no borra nada', function (): void {
    $compra = compraDelMes();
    Storage::disk('public')->put('facturas/x/a.webp', 'A');
    $compra->forceFill(['fotos_factura' => ['facturas/x/a.webp']])->save();

    $reporte = $this->service->generar($this->periodo);
    unlink($reporte->rutaAbsoluta());

    $this->travelTo(now()->addDays(10));

    expect(app(PurgarFotosFacturasService::class)->purgar())->toBe(0)
        ->and(Storage::disk('public')->exists('facturas/x/a.webp'))->toBeTrue()
        ->and($reporte->refresh()->fotos_purgadas_at)->toBeNull();
});

test('una foto subida DESPUÉS del reporte sobrevive la purga', function (): void {
    $compra = compraDelMes();
    Storage::disk('public')->put('facturas/x/vieja.webp', 'V');
    $compra->forceFill(['fotos_factura' => ['facturas/x/vieja.webp']])->save();

    $this->service->generar($this->periodo);

    // La factura de última hora llega DESPUÉS de generado el reporte.
    Storage::disk('public')->put('facturas/x/tardia.webp', 'T');
    $compra->forceFill(['fotos_factura' => ['facturas/x/vieja.webp', 'facturas/x/tardia.webp']])->save();

    $this->travelTo(now()->addDays(8));
    app(PurgarFotosFacturasService::class)->purgar();

    expect(Storage::disk('public')->exists('facturas/x/vieja.webp'))->toBeFalse()
        ->and(Storage::disk('public')->exists('facturas/x/tardia.webp'))->toBeTrue()
        ->and($compra->refresh()->fotos_factura)->toBe(['facturas/x/tardia.webp']);
});
