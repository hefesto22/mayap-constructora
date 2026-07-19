<?php

declare(strict_types=1);

namespace App\Filament\Resources\CuentasPorCobrar\Actions;

use App\Enums\EstadoCuentaPorCobrar;
use App\Exceptions\Cobranza\CobroInvalidoException;
use App\Models\CuentaPorCobrar;
use App\Services\Cobranza\CobrarService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Acción "Cobrar" sobre una cuenta por cobrar. Captura el pago del cliente y
 * delega en CobrarService, que es la única puerta que mueve el saldo. Espejo
 * de la acción Abonar de cuentas por pagar.
 *
 * Los campos del modal y la ejecución están extraídos (campos / ejecutar)
 * para que otras pantallas cobren la MISMA cuenta sin duplicar el formulario:
 * AccionCobrarProyecto los reutiliza desde la página del proyecto.
 */
final class AccionCobrar
{
    public static function make(): Action
    {
        return Action::make('cobrar')
            ->label('Registrar cobro')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalHeading('Registrar cobro')
            ->modalDescription('Registra un pago del cliente contra esta cuenta. El saldo y el estado se actualizan automáticamente.')
            ->modalSubmitActionLabel('Registrar cobro')
            ->visible(fn (CuentaPorCobrar $record): bool => $record->estado !== EstadoCuentaPorCobrar::Pagada)
            ->schema(self::campos(static fn (mixed $record): ?CuentaPorCobrar => $record instanceof CuentaPorCobrar ? $record : null))
            ->action(function (CuentaPorCobrar $record, array $data): void {
                if (self::ejecutar($record, $data)) {
                    Notification::make()
                        ->title('Cobro registrado')
                        ->body('El saldo de la cuenta se actualizó.')
                        ->success()
                        ->send();
                }
            });
    }

    /**
     * Campos del modal de cobro, parametrizados por un resolver que obtiene
     * la cuenta desde el $record de la pantalla anfitriona (la propia CxC
     * aquí; el Proyecto en AccionCobrarProyecto).
     *
     * @param Closure(mixed): ?CuentaPorCobrar $cuenta
     *
     * @return array<int, mixed>
     */
    public static function campos(Closure $cuenta): array
    {
        $saldo = static function (mixed $record) use ($cuenta): float {
            $resuelta = $cuenta($record);

            return $resuelta === null ? 0.0 : (float) $resuelta->saldo;
        };

        return [
            TextInput::make('monto')
                ->label('Monto del cobro')
                ->numeric()
                ->required()
                ->prefix('L.')
                ->step('any')
                ->minValue(0.01)
                ->maxValue(fn (mixed $record): float => $saldo($record))
                ->helperText(fn (mixed $record): string => 'Saldo pendiente: L. '.number_format($saldo($record), 2)),

            DatePicker::make('fecha')
                ->label('Fecha del cobro')
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
                ->helperText('No. de recibo, transferencia o cheque (opcional).'),

            TextInput::make('notas')
                ->label('Notas')
                ->maxLength(255),
        ];
    }

    /**
     * Ejecuta el cobro vía CobrarService. Devuelve false (y notifica el
     * error) si el dominio lo rechaza — el caller decide el mensaje de
     * éxito porque cada pantalla informa distinto.
     *
     * @param array<string, mixed> $data
     */
    public static function ejecutar(CuentaPorCobrar $cuenta, array $data): bool
    {
        $userId = auth()->id();
        $userId = is_numeric($userId) ? (int) $userId : null;

        try {
            app(CobrarService::class)->cobrar(
                cuenta: $cuenta,
                monto: (string) $data['monto'],
                fecha: isset($data['fecha']) ? (string) $data['fecha'] : null,
                metodo: $data['metodo'] ?? null,
                referencia: $data['referencia'] ?? null,
                userId: $userId,
                notas: $data['notas'] ?? null,
            );
        } catch (CobroInvalidoException $e) {
            Notification::make()
                ->title('No se pudo registrar el cobro')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return false;
        }

        return true;
    }
}
