<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReportesFiscales\Pages;

use App\Enums\TipoReporteFiscal;
use App\Filament\Resources\ReportesFiscales\ReporteFiscalResource;
use App\Models\ReporteFiscal;
use App\Services\Reportes\GenerarReporteFiscalMensualService;
use App\Services\Reportes\GenerarReportePagosMensualService;
use App\Services\Reportes\NotificadorReportesFiscales;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Historial de reportes fiscales + "Generar reporte" manual: elegir
 * el tipo (facturas o pagos) y cualquier mes (por defecto el anterior)
 * y regenerarlo al momento — útil si el PDF salió dañado o si subieron
 * fotos tarde y quieren rearchivarlas antes de la purga.
 */
class ListReportesFiscales extends ListRecords
{
    protected static string $resource = ReporteFiscalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generar')
                ->label('Generar reporte')
                ->icon('heroicon-o-document-plus')
                ->visible(fn (): bool => auth()->user()?->can('create', ReporteFiscal::class) ?? false)
                ->modalHeading('Generar reporte mensual')
                ->modalDescription('Genera (o regenera) el PDF del mes elegido con los datos y fotos actuales. Regenerar un mes reemplaza su PDF y reinicia el colchón de 7 días de sus fotos.')
                ->modalSubmitActionLabel('Generar')
                ->schema([
                    Select::make('tipo')
                        ->label('Tipo de reporte')
                        ->options(TipoReporteFiscal::options())
                        ->default(TipoReporteFiscal::Facturas->value)
                        ->required()
                        ->native(false)
                        ->helperText('Facturas: compras del mes con sus facturas. Pagos: abonos a proveedores con sus comprobantes.'),

                    DatePicker::make('mes')
                        ->label('Mes del reporte')
                        ->default(now()->subMonthNoOverflow()->startOfMonth())
                        ->maxDate(now())
                        ->required()
                        ->native(false)
                        ->displayFormat('F Y')
                        ->helperText('Se toma el mes completo de la fecha elegida.'),
                ])
                ->action(function (array $data): void {
                    $mes = Carbon::parse((string) $data['mes']);
                    $tipo = TipoReporteFiscal::from((string) $data['tipo']);

                    try {
                        $reporte = match ($tipo) {
                            TipoReporteFiscal::Facturas => app(GenerarReporteFiscalMensualService::class)->generar($mes),
                            TipoReporteFiscal::Pagos    => app(GenerarReportePagosMensualService::class)->generar($mes),
                        };

                        app(NotificadorReportesFiscales::class)->reporteGenerado($reporte);
                    } catch (Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('No se pudo generar el reporte')
                            ->body($e->getMessage())
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title('Reporte generado')
                        ->body("{$reporte->periodoLabel()} ({$tipo->getLabel()}): {$reporte->compras_count} registro(s), {$reporte->fotos_count} foto(s) archivadas.")
                        ->send();
                }),
        ];
    }
}
