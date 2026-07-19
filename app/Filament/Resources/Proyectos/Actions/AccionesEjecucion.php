<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Actions;

use App\Enums\EstadoProyecto;
use App\Enums\ModoPlazo;
use App\Exceptions\Proyectos\ProyectoException;
use App\Models\Proyecto;
use App\Services\Proyectos\AjustarPlazoProyectoService;
use App\Services\Proyectos\CambiarEstadoEjecucionService;
use App\Services\Proyectos\FinalizarRentaService;
use App\Services\Proyectos\IniciarProyectoService;
use App\Services\Proyectos\RegistrarAnticipoService;
use App\Support\Permisos;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Acciones de la fase de EJECUCIÓN de un proyecto. Cada una llama a su
 * Service de dominio (única puerta de la máquina de estados) y solo es
 * visible cuando el estado actual la permite.
 *
 * Se reutilizan tanto en la tabla (recordActions) como en la página de
 * edición (header actions). Los errores de dominio se capturan y se
 * muestran como notificación, nunca como excepción cruda al usuario.
 */
final class AccionesEjecucion
{
    /**
     * Aprobada → En ejecución. Define inicio, plazo y modo; el sistema
     * calcula la fecha de fin estimada y arranca el reloj del plazo.
     */
    public static function iniciar(): Action
    {
        return Action::make('iniciar_proyecto')
            ->label('Iniciar proyecto')
            ->icon('heroicon-o-play')
            ->color('primary')
            ->visible(fn (?Proyecto $record): bool => $record?->estado === EstadoProyecto::Aprobada
                && self::puede(Permisos::INICIAR_PROYECTO))
            ->modalHeading('Iniciar ejecución de la obra')
            ->modalDescription('Desde la fecha de inicio empieza a correr el plazo de ejecución.')
            ->modalSubmitActionLabel('Iniciar')
            ->schema([
                DatePicker::make('fecha_inicio')
                    ->label('Fecha de inicio')
                    ->required()
                    ->default(now())
                    ->native(false),

                TextInput::make('plazo_dias')
                    ->label('Plazo de ejecución')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->suffix('días')
                    ->helperText('Cantidad de días según el modo elegido.'),

                Select::make('modo_plazo')
                    ->label('Modo del plazo')
                    ->required()
                    ->options(ModoPlazo::options())
                    ->default(ModoPlazo::Calendario->value)
                    ->native(false),
            ])
            ->action(function (Proyecto $record, array $data): void {
                self::ejecutarConManejo(function () use ($record, $data): void {
                    $proyecto = app(IniciarProyectoService::class)->ejecutar(
                        $record,
                        Carbon::parse((string) $data['fecha_inicio']),
                        (int) $data['plazo_dias'],
                        ModoPlazo::from((string) $data['modo_plazo']),
                    );

                    Notification::make()
                        ->success()
                        ->title('Proyecto en ejecución')
                        ->body('Fin estimado: '.$proyecto->fecha_fin_estimada?->format('d/M/Y'))
                        ->send();
                });
            });
    }

    /**
     * Registra el anticipo / depósito del cliente. Disponible desde
     * Aprobada o durante la ejecución.
     */
    public static function registrarAnticipo(): Action
    {
        return Action::make('registrar_anticipo')
            ->label('Registrar anticipo')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            // Rentas NO: su anticipo es un COBRO contra la CxC
            // (AccionCobrarProyecto) — una sola puerta de dinero por tipo.
            ->visible(fn (?Proyecto $record): bool => $record !== null && ! $record->esRenta() && in_array(
                $record->estado,
                [EstadoProyecto::Aprobada, EstadoProyecto::EnEjecucion, EstadoProyecto::Pausada],
                strict: true,
            ) && self::puede(Permisos::REGISTRAR_ANTICIPO_PROYECTO))
            ->modalHeading('Registrar anticipo del cliente')
            ->modalSubmitActionLabel('Registrar')
            ->schema([
                TextInput::make('monto')
                    ->label('Monto del anticipo')
                    ->required()
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->prefix('L'),

                DatePicker::make('fecha')
                    ->label('Fecha del anticipo')
                    ->required()
                    ->default(now())
                    ->native(false),
            ])
            ->action(function (Proyecto $record, array $data): void {
                self::ejecutarConManejo(function () use ($record, $data): void {
                    app(RegistrarAnticipoService::class)->ejecutar(
                        $record,
                        (string) $data['monto'],
                        Carbon::parse((string) $data['fecha']),
                    );

                    Notification::make()
                        ->success()
                        ->title('Anticipo registrado')
                        ->body('L '.number_format((float) $data['monto'], 2))
                        ->send();
                });
            });
    }

    /**
     * Ajusta el plazo de una obra ya iniciada (corrección de fecha de
     * inicio, días o modo). Recalcula la fecha de fin estimada.
     */
    public static function ajustarPlazo(): Action
    {
        return Action::make('ajustar_plazo')
            ->label('Ajustar plazo')
            ->icon('heroicon-o-calendar-days')
            ->color('warning')
            ->visible(fn (?Proyecto $record): bool => $record !== null && in_array(
                $record->estado,
                [EstadoProyecto::EnEjecucion, EstadoProyecto::Pausada],
                strict: true,
            ) && self::puede(Permisos::AJUSTAR_PLAZO_PROYECTO))
            ->modalHeading('Ajustar plazo de la obra')
            ->modalDescription('Corregí la fecha de inicio, los días o el modo. Se recalcula la fecha de fin estimada.')
            ->modalSubmitActionLabel('Guardar ajuste')
            ->fillForm(fn (?Proyecto $record): array => [
                'fecha_inicio' => $record?->fecha_inicio?->toDateString(),
                'plazo_dias'   => $record?->plazo_dias,
                'modo_plazo'   => $record?->modo_plazo?->value,
            ])
            ->schema([
                DatePicker::make('fecha_inicio')
                    ->label('Fecha de inicio')
                    ->required()
                    ->native(false),

                TextInput::make('plazo_dias')
                    ->label('Plazo de ejecución')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->suffix('días'),

                Select::make('modo_plazo')
                    ->label('Modo del plazo')
                    ->required()
                    ->options(ModoPlazo::options())
                    ->native(false),
            ])
            ->action(function (Proyecto $record, array $data): void {
                self::ejecutarConManejo(function () use ($record, $data): void {
                    $proyecto = app(AjustarPlazoProyectoService::class)->ejecutar(
                        $record,
                        Carbon::parse((string) $data['fecha_inicio']),
                        (int) $data['plazo_dias'],
                        ModoPlazo::from((string) $data['modo_plazo']),
                    );

                    Notification::make()
                        ->success()
                        ->title('Plazo actualizado')
                        ->body('Nuevo fin estimado: '.$proyecto->fecha_fin_estimada?->format('d/M/Y'))
                        ->send();
                });
            });
    }

    /**
     * En ejecución → Pausada. Requiere motivo.
     */
    public static function pausar(): Action
    {
        return Action::make('pausar_proyecto')
            ->label('Pausar')
            ->icon('heroicon-o-pause')
            ->color('warning')
            ->visible(fn (?Proyecto $record): bool => $record?->estado === EstadoProyecto::EnEjecucion
                && self::puede(Permisos::PAUSAR_PROYECTO))
            ->modalHeading('Pausar la obra')
            ->modalSubmitActionLabel('Pausar')
            ->schema([
                Textarea::make('motivo')
                    ->label('Motivo de la pausa')
                    ->required()
                    ->rows(3)
                    ->placeholder('EJ: FALTA DE MATERIAL, LLUVIAS, ESPERANDO PERMISO'),
            ])
            ->action(function (Proyecto $record, array $data): void {
                self::ejecutarConManejo(function () use ($record, $data): void {
                    app(CambiarEstadoEjecucionService::class)->pausar($record, (string) $data['motivo']);

                    Notification::make()->success()->title('Proyecto pausado')->send();
                });
            });
    }

    /**
     * Pausada → En ejecución.
     */
    public static function reactivar(): Action
    {
        return Action::make('reactivar_proyecto')
            ->label('Reactivar')
            ->icon('heroicon-o-play')
            ->color('primary')
            ->visible(fn (?Proyecto $record): bool => $record?->estado === EstadoProyecto::Pausada
                && self::puede(Permisos::REACTIVAR_PROYECTO))
            ->requiresConfirmation()
            ->modalHeading('Reactivar la obra')
            ->modalDescription('El proyecto vuelve a estado En ejecución.')
            ->modalSubmitActionLabel('Reactivar')
            ->action(function (Proyecto $record): void {
                self::ejecutarConManejo(function () use ($record): void {
                    app(CambiarEstadoEjecucionService::class)->reactivar($record);

                    Notification::make()->success()->title('Proyecto reactivado')->send();
                });
            });
    }

    /**
     * En ejecución / Pausada → Finalizada. Fija la fecha de fin real.
     */
    public static function finalizar(): Action
    {
        return Action::make('finalizar_proyecto')
            ->label('Finalizar')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (?Proyecto $record): bool => $record !== null && in_array(
                $record->estado,
                [EstadoProyecto::EnEjecucion, EstadoProyecto::Pausada],
                strict: true,
            ) && self::puede(Permisos::FINALIZAR_PROYECTO))
            ->modalHeading('Finalizar la obra')
            ->modalSubmitActionLabel('Finalizar')
            ->schema([
                DatePicker::make('fecha_fin_real')
                    ->label('Fecha de finalización')
                    ->required()
                    ->default(now())
                    ->native(false),
            ])
            ->action(function (Proyecto $record, array $data): void {
                self::ejecutarConManejo(function () use ($record, $data): void {
                    $fecha = Carbon::parse((string) $data['fecha_fin_real']);

                    // Rentas: la regla de cobro es distinta — se cobra lo
                    // cotizado como mínimo y el extra de horas reales se
                    // suma a la cuenta por cobrar al finalizar.
                    if ($record->esRenta()) {
                        $usuarioId = auth()->id();

                        $resultado = app(FinalizarRentaService::class)
                            ->finalizar($record, $fecha, is_numeric($usuarioId) ? (int) $usuarioId : null);

                        $conExtra = bccomp($resultado['extra'], '0', 2) > 0;

                        Notification::make()
                            ->success()
                            ->title('Renta finalizada')
                            ->body($conExtra
                                ? 'Horas reales sobre lo pactado: se sumó L '
                                    .number_format((float) $resultado['extra'], 2)
                                    .' a la cuenta por cobrar.'
                                : 'Sin horas extra: se cobra lo cotizado.')
                            ->send();

                        return;
                    }

                    app(CambiarEstadoEjecucionService::class)->finalizar($record, $fecha);

                    Notification::make()->success()->title('Proyecto finalizado')->send();
                });
            });
    }

    /**
     * Aprobada / En ejecución / Pausada → Cancelada. Requiere motivo.
     */
    public static function cancelar(): Action
    {
        return Action::make('cancelar_proyecto')
            ->label('Cancelar')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (?Proyecto $record): bool => $record !== null && in_array(
                $record->estado,
                [EstadoProyecto::Aprobada, EstadoProyecto::EnEjecucion, EstadoProyecto::Pausada],
                strict: true,
            ) && self::puede(Permisos::CANCELAR_PROYECTO))
            ->modalHeading('Cancelar el proyecto')
            ->modalDescription('Acción definitiva. El proyecto queda cancelado con el motivo registrado.')
            ->modalSubmitActionLabel('Sí, cancelar proyecto')
            ->schema([
                Textarea::make('motivo')
                    ->label('Motivo de la cancelación')
                    ->required()
                    ->rows(3)
                    ->placeholder('EJ: CLIENTE DESISTIÓ, FALTA DE FINANCIAMIENTO'),
            ])
            ->action(function (Proyecto $record, array $data): void {
                self::ejecutarConManejo(function () use ($record, $data): void {
                    app(CambiarEstadoEjecucionService::class)->cancelar($record, (string) $data['motivo']);

                    Notification::make()->success()->title('Proyecto cancelado')->send();
                });
            });
    }

    /**
     * Ejecuta la acción y traduce cualquier error de dominio del módulo
     * Proyectos a una notificación de error legible.
     *
     * @param callable(): void $callback
     */
    private static function ejecutarConManejo(callable $callback): void
    {
        try {
            $callback();
        } catch (ProyectoException $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo completar la acción')
                ->body($e->getMessage())
                ->send();
        }
    }

    /**
     * Permisos GRANULARES de ejecución (Iniciar/Pausar/Finalizar/Cancelar/
     * Anticipo/Plazo :Proyecto) — se crean en RolesInventarioSeeder y se
     * gestionan desde la pantalla de Roles (Shield). El super_admin pasa
     * por el Gate::before de Shield.
     */
    private static function puede(string $permiso): bool
    {
        return auth()->user()?->can($permiso) ?? false;
    }
}
