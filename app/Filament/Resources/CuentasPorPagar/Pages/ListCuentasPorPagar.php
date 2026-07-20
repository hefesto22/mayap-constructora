<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorPagar\Pages;

use App\Filament\Resources\CuentasPorPagar\CuentaPorPagarResource;
use App\Models\CuentaPorPagar;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCuentasPorPagar extends ListRecords
{
    protected static string $resource = CuentaPorPagarResource::class;

    /**
     * Tabs por urgencia de pago (decisión Mauricio 2026-07-20: "alerta
     * de cuáles son las más importantes a pagar según fecha") — espejo
     * de la cobranza, pero de lo que DEBEMOS:
     *
     *  1. Vencidas   — pagos atrasados, coordinar YA (tab por defecto si hay)
     *  2. Por vencer — vencen en los próximos 7 días, preparar el pago
     *  3. Con saldo  — toda la deuda viva con proveedores
     *  4. Todas      — histórico completo (incluye pagadas)
     */
    public function getTabs(): array
    {
        $vencidas = CuentaPorPagar::query()->vencidas()->count();
        $porVencer = CuentaPorPagar::query()->porVencer()->count();
        $conSaldo = CuentaPorPagar::query()->pendientes()->count();

        return [
            'vencidas' => Tab::make('Vencidas')
                ->icon('heroicon-o-exclamation-triangle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->vencidas())
                ->badge($vencidas)
                ->badgeColor($vencidas > 0 ? 'danger' : 'gray'),

            'por_vencer' => Tab::make('Por vencer (7 días)')
                ->icon('heroicon-o-clock')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->porVencer())
                ->badge($porVencer)
                ->badgeColor($porVencer > 0 ? 'warning' : 'gray'),

            'con_saldo' => Tab::make('Con saldo')
                ->icon('heroicon-o-banknotes')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->pendientes())
                ->badge($conSaldo),

            'todas' => Tab::make('Todas')
                ->icon('heroicon-o-list-bullet'),
        ];
    }

    /**
     * Aterrizar donde duele, pero nunca en una pestaña vacía:
     * vencidas si hay atrasos, por vencer si hay urgencias, y si no,
     * toda la deuda con saldo.
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return match (true) {
            CuentaPorPagar::query()->vencidas()->exists()  => 'vencidas',
            CuentaPorPagar::query()->porVencer()->exists() => 'por_vencer',
            default                                        => 'con_saldo',
        };
    }
}
