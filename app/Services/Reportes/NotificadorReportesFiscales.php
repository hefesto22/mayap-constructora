<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Enums\TipoReporteFiscal;
use App\Filament\Resources\ReportesFiscales\ReporteFiscalResource;
use App\Models\ReporteFiscal;
use App\Models\User;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Campanitas del ciclo fiscal mensual — a gerencia y recepción (la
 * oficina que gestiona compras y pagos). notifyNow, best-effort, por
 * rol: mismo patrón que cobranza y mantenimiento. Cubre los dos tipos
 * de reporte (facturas de compras y pagos a proveedores).
 */
final class NotificadorReportesFiscales
{
    /**
     * "El reporte del mes ya está listo para descargar" — al generarse.
     */
    public function reporteGenerado(ReporteFiscal $reporte): void
    {
        $body = match ($reporte->tipo) {
            TipoReporteFiscal::Facturas => "{$reporte->periodoLabel()}: {$reporte->compras_count} compra(s) y "
                ."{$reporte->fotos_count} foto(s) de factura archivadas en PDF. "
                .'Las fotos se liberarán del servidor el '.$reporte->fechaPurga()->format('d/m/Y').'.',
            TipoReporteFiscal::Pagos => "{$reporte->periodoLabel()}: {$reporte->compras_count} abono(s) a proveedores y "
                ."{$reporte->fotos_count} comprobante(s) de transferencia archivados en PDF. "
                .'Las fotos se liberarán del servidor el '.$reporte->fechaPurga()->format('d/m/Y').'.',
        };

        $this->despachar(
            Notification::make()
                ->title(match ($reporte->tipo) {
                    TipoReporteFiscal::Facturas => 'Reporte fiscal mensual listo',
                    TipoReporteFiscal::Pagos    => 'Reporte de pagos mensual listo',
                })
                ->body($body)
                ->icon('heroicon-o-document-arrow-down')
                ->iconColor('success')
                ->actions([$this->verReportes()]),
        );
    }

    /**
     * "Se liberó el espacio del mes" — al purgar las fotos ya archivadas.
     */
    public function fotosPurgadas(ReporteFiscal $reporte, int $borradas): void
    {
        $this->despachar(
            Notification::make()
                ->title(match ($reporte->tipo) {
                    TipoReporteFiscal::Facturas => 'Fotos de facturas liberadas',
                    TipoReporteFiscal::Pagos    => 'Comprobantes de pago liberados',
                })
                ->body(
                    "{$reporte->periodoLabel()}: {$borradas} foto(s) borradas del servidor. "
                    .'Todas quedaron archivadas en el PDF del reporte de '
                    .($reporte->tipo === TipoReporteFiscal::Pagos ? 'pagos' : 'facturas').'.'
                )
                ->icon('heroicon-o-archive-box')
                ->iconColor('info')
                ->actions([$this->verReportes()]),
        );
    }

    private function verReportes(): Action
    {
        return Action::make('ver')
            ->label('Ver reportes')
            ->url(ReporteFiscalResource::getUrl())
            ->button();
    }

    private function despachar(Notification $notificacion): void
    {
        $this->oficina()
            ->each(fn (User $user) => $user->notifyNow($notificacion->toDatabase()));
    }

    /**
     * Busca por relación (NO con el scope `role()` de Spatie, que LANZA
     * si el rol no está sembrado): notificar es best-effort.
     *
     * @return Collection<int, User>
     */
    private function oficina(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Roles::GERENCIA, Roles::RECEPCION]))
            ->where('is_active', true)
            ->get();
    }
}
