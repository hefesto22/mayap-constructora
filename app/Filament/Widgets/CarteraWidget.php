<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\CuentasPorCobrar\CuentaPorCobrarResource;
use App\Models\Cobro;
use App\Models\CuentaPorCobrar;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Cartera de clientes en el Escritorio — cierra el ciclo de cobranza:
 * de un vistazo cuánto deben, qué ya venció (morosos), qué vence esta
 * semana y cuánto entró en el mes. Cada tarjeta lleva a SU pestaña del
 * módulo de Cuentas por Cobrar.
 *
 * Visible solo para quien maneja dinero: mismo permiso que el módulo
 * (ViewAny:CuentaPorCobrar) — el encargado de obra no lo ve.
 */
class CarteraWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -5;

    protected ?string $heading = 'Cartera de clientes';

    public static function canView(): bool
    {
        return auth()->user()?->can('ViewAny:CuentaPorCobrar') ?? false;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $datos = self::datos();

        return [
            Stat::make('Por cobrar', 'L '.number_format($datos['saldo_total'], 2))
                ->description($datos['cuentas_con_saldo'].' cuenta(s) con saldo')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('info')
                ->url(CuentaPorCobrarResource::getUrl(parameters: ['activeTab' => 'con_saldo'])),

            Stat::make('Vencidas', 'L '.number_format($datos['vencidas_monto'], 2))
                ->description($datos['vencidas_count'] > 0
                    ? $datos['vencidas_count'].' cuenta(s) en mora — gestionar cobro'
                    : 'Sin cuentas en mora')
                ->descriptionIcon($datos['vencidas_count'] > 0
                    ? 'heroicon-o-exclamation-triangle'
                    : 'heroicon-o-check-circle')
                ->color($datos['vencidas_count'] > 0 ? 'danger' : 'success')
                ->url(CuentaPorCobrarResource::getUrl(parameters: ['activeTab' => 'vencidas'])),

            Stat::make('Vence en 7 días', 'L '.number_format($datos['por_vencer_monto'], 2))
                ->description($datos['por_vencer_count'].' cuenta(s) por vencer')
                ->descriptionIcon('heroicon-o-clock')
                ->color($datos['por_vencer_count'] > 0 ? 'warning' : 'success')
                ->url(CuentaPorCobrarResource::getUrl(parameters: ['activeTab' => 'por_vencer'])),

            Stat::make('Cobrado este mes', 'L '.number_format($datos['cobrado_mes'], 2))
                ->description('Cobros desde el 1 de '.now()->translatedFormat('F'))
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color('success'),
        ];
    }

    /**
     * Números de la cartera — separado del render para poder probarlo
     * como datos puros.
     *
     * @return array{saldo_total: float, cuentas_con_saldo: int, vencidas_monto: float, vencidas_count: int, por_vencer_monto: float, por_vencer_count: int, cobrado_mes: float}
     */
    public static function datos(): array
    {
        $conSaldo = CuentaPorCobrar::query()->where('saldo', '>', 0);
        $vencidas = CuentaPorCobrar::query()->vencidas();
        $porVencer = CuentaPorCobrar::query()->porVencer();

        return [
            'saldo_total'       => (float) $conSaldo->clone()->sum('saldo'),
            'cuentas_con_saldo' => $conSaldo->clone()->count(),
            'vencidas_monto'    => (float) $vencidas->clone()->sum('saldo'),
            'vencidas_count'    => $vencidas->clone()->count(),
            'por_vencer_monto'  => (float) $porVencer->clone()->sum('saldo'),
            'por_vencer_count'  => $porVencer->clone()->count(),
            'cobrado_mes'       => (float) Cobro::query()
                ->whereDate('fecha', '>=', today()->startOfMonth())
                ->sum('monto'),
        ];
    }
}
