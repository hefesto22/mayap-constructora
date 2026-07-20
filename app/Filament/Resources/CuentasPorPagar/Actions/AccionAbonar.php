<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorPagar\Actions;

use App\Enums\EstadoCuentaPorPagar;
use App\Exceptions\Compras\AbonoInvalidoException;
use App\Models\CuentaPorPagar;
use App\Services\Compras\AbonarService;
use App\Support\ImageOptimizer;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Acción "Abonar" sobre una cuenta por pagar. Construye el modal de captura
 * (monto, fecha, método, referencia, foto del comprobante, notas) y delega
 * en AbonarService, que es la única puerta que mueve el saldo. Se usa igual
 * desde la tabla y desde la página de vista — sin duplicar la lógica.
 *
 * Foto del comprobante (decisión Mauricio 2026-07-20): UNA por abono,
 * convertida a WebP al subirla. Se archiva en el reporte mensual de pagos
 * y una semana después se libera del servidor (el PDF queda de respaldo).
 */
final class AccionAbonar
{
    public static function make(): Action
    {
        return Action::make('abonar')
            ->label('Abonar')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalHeading('Registrar abono')
            ->modalDescription('Registra un pago contra esta cuenta. El saldo y el estado se actualizan automáticamente.')
            ->modalSubmitActionLabel('Registrar abono')
            ->visible(fn (CuentaPorPagar $record): bool => $record->estado !== EstadoCuentaPorPagar::Pagada)
            ->schema([
                TextInput::make('monto')
                    ->label('Monto del abono')
                    ->numeric()
                    ->required()
                    ->prefix('L.')
                    ->step('any')
                    ->minValue(0.01)
                    ->maxValue(fn (CuentaPorPagar $record): float => (float) $record->saldo)
                    ->helperText(fn (CuentaPorPagar $record): string => 'Saldo pendiente: L. '.number_format((float) $record->saldo, 2)),

                DatePicker::make('fecha')
                    ->label('Fecha del abono')
                    ->default(now())
                    ->required()
                    ->native(false)
                    ->maxDate(now()),

                Select::make('metodo')
                    ->label('Método de pago')
                    ->options([
                        'EFECTIVO'      => 'Efectivo',
                        'CHEQUE'        => 'Cheque',
                        'TRANSFERENCIA' => 'Transferencia',
                        'TARJETA'       => 'Tarjeta',
                    ])
                    ->native(false),

                TextInput::make('referencia')
                    ->label('Referencia')
                    ->maxLength(100)
                    ->helperText('No. de cheque, transferencia o recibo (opcional).'),

                FileUpload::make('foto_comprobante')
                    ->label('Foto del comprobante')
                    ->image()
                    ->maxSize(10240)
                    ->disk('public')
                    ->imagePreviewHeight('160')
                    ->openable()
                    ->downloadable()
                    ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => ImageOptimizer::toWebp(
                        $file,
                        'comprobantes/'.now()->format('Y-m'),
                    ))
                    ->deleteUploadedFileUsing(fn (string $file) => Storage::disk('public')->delete($file))
                    ->helperText('La transferencia o depósito que respalda este abono. Se convierte a WebP, se archiva en el reporte mensual de pagos y luego se libera del servidor.'),

                TextInput::make('notas')
                    ->label('Notas')
                    ->maxLength(255),
            ])
            ->action(function (CuentaPorPagar $record, array $data): void {
                $userId = auth()->id();
                $userId = is_numeric($userId) ? (int) $userId : null;

                $foto = $data['foto_comprobante'] ?? null;

                try {
                    app(AbonarService::class)->abonar(
                        cuenta: $record,
                        monto: (string) $data['monto'],
                        fecha: isset($data['fecha']) ? (string) $data['fecha'] : null,
                        metodo: $data['metodo'] ?? null,
                        referencia: $data['referencia'] ?? null,
                        userId: $userId,
                        notas: $data['notas'] ?? null,
                        fotoComprobante: is_string($foto) && $foto !== '' ? $foto : null,
                    );
                } catch (AbonoInvalidoException $e) {
                    Notification::make()
                        ->title('No se pudo registrar el abono')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Abono registrado')
                    ->body('El saldo de la cuenta se actualizó.')
                    ->success()
                    ->send();

                // Refresca la instancia que muestra el infolist de Ver:
                // sin esto el saldo y el estado se ven viejos hasta
                // recargar (visto en prueba de navegador 2026-07-20).
                $record->refresh();
            });
    }
}
