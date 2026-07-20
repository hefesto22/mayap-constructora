<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Filament v4 toma control de "/" porque el panel está configurado con
| ->path('/') en AdminPanelProvider. NO definir aquí Route::get('/') —
| Filament lo perderá si la ruta web tiene mayor prioridad.
|
| Este archivo queda disponible para rutas custom adicionales (webhooks,
| callbacks OAuth, endpoints públicos puntuales) que NO conflictúen con
| las rutas de Filament.
|
| Las rutas internas del panel (/login, /dashboard, /users, /shield/roles,
| /horizon, etc.) las gestiona Filament automáticamente.
*/

use App\Http\Controllers\Reportes\ActaRecepcionPdfController;
use App\Http\Controllers\Reportes\ComposicionProyectoPdfController;
use App\Http\Controllers\Reportes\CostoObraPdfController;
use App\Http\Controllers\Reportes\CotizacionRentaImagenController;
use App\Http\Controllers\Reportes\CotizacionRentaPdfController;
use App\Http\Controllers\Reportes\ReciboPagoPdfController;
use Illuminate\Support\Facades\Route;

/*
| Vista previa de reportes PDF — se sirven INLINE (visor del navegador en
| pestaña nueva) en vez de forzar descarga. Cada controller re-valida el
| permiso en servidor: la URL directa sin permiso responde 403.
*/
Route::middleware(['auth', 'throttle:pdfs'])
    ->prefix('reportes')
    ->name('reportes.')
    ->group(function (): void {
        Route::get('compras/{compra}/acta-recepcion', ActaRecepcionPdfController::class)
            ->name('acta-recepcion');

        Route::get('proyectos/{proyecto}/costos', CostoObraPdfController::class)
            ->name('costo-obra');

        Route::get('proyectos/{proyecto}/composicion', ComposicionProyectoPdfController::class)
            ->name('composicion-proyecto');

        // Cotización de renta: PDF inline + imagen PNG de descarga (para
        // adjuntarla en el WhatsApp del cliente). Solo proyectos renta.
        Route::get('proyectos/{proyecto}/cotizacion-renta', CotizacionRentaPdfController::class)
            ->name('cotizacion-renta');

        Route::get('proyectos/{proyecto}/cotizacion-renta-imagen', CotizacionRentaImagenController::class)
            ->name('cotizacion-renta-imagen');

        // Recibos de pago de una planilla CERRADA (uno por página).
        Route::get('planillas/{planilla}/recibos', ReciboPagoPdfController::class)
            ->name('recibos-planilla');
    });
