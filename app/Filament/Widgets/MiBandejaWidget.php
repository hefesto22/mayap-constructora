<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\EstadoCompra;
use App\Enums\EstadoRequisicion;
use App\Filament\Resources\Compras\CompraResource;
use App\Filament\Resources\Requisiciones\RequisicionResource;
use App\Models\Compra;
use App\Models\Requisicion;
use App\Models\User;
use App\Support\Roles;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * MI BANDEJA (Fase G1) — lo pendiente PARA MÍ según mi rol, con link
 * directo al listado filtrado. Cada rol entra al sistema y ve su trabajo
 * sin buscar:
 *
 *  - bodeguero/gerencia: requisiciones por autorizar y por despachar.
 *  - recepcion:          requisiciones sin stock esperando compra.
 *  - encargado_obra:     entregas en tránsito hacia SUS obras.
 *
 * Un usuario con varios roles ve la suma de sus bandejas. El super_admin
 * ve todas (visión de dueño).
 */
class MiBandejaWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Mi bandeja';

    protected static ?int $sort = -10;

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $stats = [];

        // ── Bodega: autorizar y despachar ───────────────────────────────
        if (Roles::despachaBodega($user)) {
            $porAutorizar = Requisicion::query()->enEstado(EstadoRequisicion::Solicitada)->count();
            $porDespachar = Requisicion::query()->enEstado(EstadoRequisicion::Autorizada)->count();

            $stats[] = Stat::make('Requisiciones por autorizar', $porAutorizar)
                ->description('Obras esperando visto bueno')
                ->color($porAutorizar > 0 ? 'warning' : 'success')
                ->url(self::urlRequisiciones(EstadoRequisicion::Solicitada));

            $stats[] = Stat::make('Por despachar de bodega', $porDespachar)
                ->description('Autorizadas listas para salir')
                ->color($porDespachar > 0 ? 'info' : 'success')
                ->url(self::urlRequisiciones(EstadoRequisicion::Autorizada));

            // G2: compras en camino que hay que CONTAR al llegar.
            $porRecibir = Compra::query()->enEstado(EstadoCompra::PorRecibir)->count();

            $stats[] = Stat::make('Compras por recibir', $porRecibir)
                ->description('Verificar lo que llegue contra la factura')
                ->color($porRecibir > 0 ? 'warning' : 'success')
                ->url(self::urlComprasPorRecibir());
        }

        // ── Recepción: comprar lo que bodega no tiene ───────────────────
        if (Roles::compra($user)) {
            $porComprar = Requisicion::query()->enEstado(EstadoRequisicion::RequisicionCompra)->count();

            $stats[] = Stat::make('Compras pendientes', $porComprar)
                ->description('Requisiciones sin stock esperando compra')
                ->color($porComprar > 0 ? 'danger' : 'success')
                ->url(self::urlRequisiciones(EstadoRequisicion::RequisicionCompra));
        }

        // ── Encargado: lo que viene en camino a SUS obras ───────────────
        if ($user->hasRole(Roles::ENCARGADO_OBRA)) {
            $enCamino = Requisicion::query()
                ->enEstado(EstadoRequisicion::EnTransito)
                ->whereHas('proyecto.encargados', fn ($q) => $q->whereKey($user->id))
                ->count();

            $stats[] = Stat::make('Entregas por confirmar', $enCamino)
                ->description('Material en camino a tus obras')
                ->color($enCamino > 0 ? 'warning' : 'success')
                ->url(self::urlRequisiciones(EstadoRequisicion::EnTransito));

            // G2: compras con material directo a SUS obras por verificar.
            $comprasPorVerificar = Compra::query()
                ->enEstado(EstadoCompra::PorRecibir)
                ->where(function ($q) use ($user): void {
                    $obras = $user->obrasEncargadas()->pluck('proyectos.id');

                    $q->whereIn('proyecto_id', $obras)
                        ->orWhereHas('lineas', fn ($l) => $l->whereIn('proyecto_id', $obras));
                })
                ->count();

            $stats[] = Stat::make('Compras por verificar', $comprasPorVerificar)
                ->description('Material comprado en camino a tus obras')
                ->color($comprasPorVerificar > 0 ? 'warning' : 'success')
                ->url(self::urlComprasPorRecibir());
        }

        return $stats;
    }

    private static function urlComprasPorRecibir(): string
    {
        return CompraResource::getUrl('index', [
            'tableFilters' => ['estado' => ['value' => EstadoCompra::PorRecibir->value]],
        ]);
    }

    private static function urlRequisiciones(EstadoRequisicion $estado): string
    {
        return RequisicionResource::getUrl('index', [
            'tableFilters' => ['estado' => ['value' => $estado->value]],
        ]);
    }
}
