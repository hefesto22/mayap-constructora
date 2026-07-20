<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReportesFiscales;

use App\Enums\TipoReporteFiscal;
use App\Filament\Resources\ReportesFiscales\Pages\ListReportesFiscales;
use App\Models\ReporteFiscal;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes fiscales mensuales — el historial de PDFs de control: cada
 * mes con su reporte de FACTURAS (compras + fotos archivadas) y su
 * reporte de PAGOS (abonos a proveedores + comprobantes), y el botón de
 * descarga. Solo lectura: los reportes se generan solos (scheduler) o
 * con el botón de la cabecera; nunca se editan ni se borran.
 */
class ReporteFiscalResource extends Resource
{
    protected static ?string $model = ReporteFiscal::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $modelLabel = 'Reporte fiscal';

    protected static ?string $pluralModelLabel = 'Reportes fiscales';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return 'Compras';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('periodo')
                    ->label('Período')
                    ->state(fn (ReporteFiscal $record): string => $record->periodoLabel())
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (TipoReporteFiscal $state): string => $state->getLabel())
                    ->color(fn (TipoReporteFiscal $state): string => $state->getColor())
                    ->sortable(),

                TextColumn::make('compras_count')
                    ->label('Registros')
                    ->description(fn (ReporteFiscal $record): string => $record->tipo === TipoReporteFiscal::Pagos
                        ? 'abonos'
                        : 'compras')
                    ->alignEnd(),

                TextColumn::make('fotos_count')
                    ->label('Fotos archivadas')
                    ->alignEnd(),

                TextColumn::make('estado_fotos')
                    ->label('Espacio')
                    ->badge()
                    ->state(fn (ReporteFiscal $record): string => match (true) {
                        ! $record->pdfSano()                => 'PDF dañado',
                        $record->fotos_purgadas_at !== null => 'Fotos liberadas',
                        $record->fotos_count === 0          => 'Sin fotos',
                        default                             => 'Se libera el '.$record->fechaPurga()->format('d/m/Y'),
                    })
                    ->color(fn (ReporteFiscal $record): string => match (true) {
                        ! $record->pdfSano()                => 'danger',
                        $record->fotos_purgadas_at !== null => 'success',
                        default                             => 'warning',
                    }),

                TextColumn::make('created_at')
                    ->label('Generado')
                    ->dateTime('d/M/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('periodo', 'desc')
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(TipoReporteFiscal::options()),
            ])
            ->recordActions([
                Action::make('descargar')
                    ->label('Descargar PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn (ReporteFiscal $record): bool => $record->pdfSano())
                    ->action(fn (ReporteFiscal $record): BinaryFileResponse => response()->download(
                        $record->rutaAbsoluta(),
                        basename($record->path),
                    )),
            ])
            ->emptyStateHeading('Aún no hay reportes fiscales')
            ->emptyStateDescription('El día 1 de cada mes se generan solos los del mes anterior (facturas y pagos) — o genera uno ahora con el botón de arriba.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReportesFiscales::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // Se generan por comando o botón, no con formulario.
    }
}
