<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Actions;

use App\Enums\EstadoCompra;
use App\Models\Compra;
use App\Support\ImageOptimizer;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Fotos de la factura del proveedor (decisión Mauricio 2026-07-19).
 *
 * Toda imagen se convierte a WEBP al subirla (ImageOptimizer) para
 * ahorrar espacio. Las fotos son TEMPORALES en el servidor: el reporte
 * fiscal mensual las archiva en PDF y, 7 días después, se purgan del
 * disco — el PDF queda como respaldo permanente.
 *
 * La acción vive en la tabla porque la factura suele llegar DESPUÉS de
 * confirmar (la compra ya no es editable); el mismo campo aparece en el
 * formulario para capturarlas desde el borrador.
 */
final class AccionFotosFactura
{
    public static function make(): Action
    {
        return Action::make('fotos_factura')
            ->label('Fotos')
            ->icon('heroicon-o-camera')
            ->color('gray')
            ->badge(fn (Compra $record): ?string => ($n = count($record->fotos_factura ?? [])) > 0 ? (string) $n : null)
            ->visible(fn (Compra $record): bool => $record->estado !== EstadoCompra::Anulada
                && ! Roles::soloEncargado(auth()->user())
                && (auth()->user()?->can('update', $record) ?? false))
            ->modalHeading(fn (Compra $record): string => 'Fotos de factura · '.$record->codigo)
            ->modalDescription('Sube las fotos del documento del proveedor. Se convierten a WebP, se archivan en el PDF del reporte fiscal mensual y una semana después se liberan del servidor.')
            ->modalSubmitActionLabel('Guardar fotos')
            ->fillForm(fn (Compra $record): array => [
                'fotos_factura' => $record->fotos_factura ?? [],
            ])
            ->schema([self::campo()])
            ->action(function (Compra $record, array $data): void {
                $fotos = array_values(array_filter((array) ($data['fotos_factura'] ?? [])));

                $record->forceFill(['fotos_factura' => $fotos === [] ? null : $fotos])->save();

                Notification::make()
                    ->success()
                    ->title('Fotos guardadas')
                    ->body(count($fotos) === 0
                        ? 'La compra quedó sin fotos de factura.'
                        : count($fotos).' foto(s) adjuntas — se archivarán en el reporte fiscal del mes.')
                    ->send();
            });
    }

    /**
     * El campo de subida, compartido con CompraForm (pestaña Datos de la
     * compra). Conversión a WebP al guardar; quitar una foto la borra
     * del disco al instante.
     */
    public static function campo(): FileUpload
    {
        return FileUpload::make('fotos_factura')
            ->label('Fotos de factura')
            ->image()
            ->multiple()
            ->maxFiles(10)
            ->maxSize(10240)
            ->disk('public')
            ->panelLayout('grid')
            ->imagePreviewHeight('120')
            ->openable()
            ->downloadable()
            ->reorderable()
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => ImageOptimizer::toWebp(
                $file,
                'facturas/'.now()->format('Y-m'),
            ))
            ->deleteUploadedFileUsing(fn (string $file) => Storage::disk('public')->delete($file))
            ->helperText('Cualquier formato de imagen: se convierte a WebP automáticamente. Se archivan en el PDF mensual y luego se liberan del servidor.')
            ->columnSpanFull();
    }
}
