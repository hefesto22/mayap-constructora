<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar\Pages;

use App\Filament\Resources\CuentasPorCobrar\CuentaPorCobrarResource;
use App\Models\CuentaPorCobrar;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCuentasPorCobrar extends ListRecords
{
    protected static string $resource = CuentaPorCobrarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva cuenta por cobrar'),
        ];
    }

    /**
     * Tabs por urgencia de cobro (decisión Mauricio 2026-07-16: "llevar
     * buen registro de quiénes deben y su fecha máxima de pago"):
     *
     *  1. Vencidas   — morosos, gestionar YA (tab por defecto si hay)
     *  2. Por vencer — vencen en los próximos 7 días, llamar al cliente
     *  3. Con saldo  — toda la cartera viva
     *  4. Todas      — histórico completo (incluye pagadas)
     */
    public function getTabs(): array
    {
        $vencidas = CuentaPorCobrar::query()->vencidas()->count();
        $porVencer = CuentaPorCobrar::query()->porVencer()->count();
        $conSaldo = CuentaPorCobrar::query()->pendientes()->count();

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
     * vencidas si hay morosos, por vencer si hay urgencias, y si no,
     * toda la cartera con saldo.
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return match (true) {
            CuentaPorCobrar::query()->vencidas()->exists()  => 'vencidas',
            CuentaPorCobrar::query()->porVencer()->exists() => 'por_vencer',
            default                                         => 'con_saldo',
        };
    }
}
