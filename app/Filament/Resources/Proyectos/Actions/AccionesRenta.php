<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Actions;

use App\Enums\EstadoProyecto;
use App\Enums\ModalidadTrabajo;
use App\Enums\UnidadRenta;
use App\Exceptions\Proyectos\ProyectoException;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\ProyectoLineaRenta;
use App\Services\Proyectos\AprobarRentaService;
use App\Services\Proyectos\ExtenderRentaService;
use App\Support\Permisos;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Acciones específicas de proyectos tipo RENTA DE MAQUINARIA.
 *
 * - Aprobar renta: un solo botón que aprueba, auto-inicia, agenda las
 *   máquinas en el calendario y genera la cuenta por cobrar (vence
 *   según la condición de pago del cliente). AprobarRentaService es la
 *   única puerta.
 * - Extender renta: "el cliente quiere más horas/días" — agrega una
 *   línea de extensión, la agenda y sube la CxC.
 *
 * Permisos: aprobar reutiliza Iniciar:Proyecto (la aprobación de una
 * renta ARRANCA la ejecución) y extender reutiliza AjustarPlazo:Proyecto
 * (extiende el compromiso pactado). Sin permisos nuevos en el seeder.
 *
 * Finalizar renta NO vive aquí: es el mismo botón Finalizar de
 * AccionesEjecucion, que detecta el tipo y cobra el extra de horas.
 */
final class AccionesRenta
{
    public static function aprobar(): Action
    {
        return Action::make('aprobar_renta')
            ->label('Aprobar renta')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (?Proyecto $record): bool => $record instanceof Proyecto
                && $record->esRenta()
                && in_array($record->estado, [EstadoProyecto::Borrador, EstadoProyecto::Enviada], strict: true)
                && self::puede(Permisos::INICIAR_PROYECTO))
            ->modalHeading('Aprobar la renta')
            ->modalDescription(
                'El cliente aceptó. Al aprobar: las máquinas quedan agendadas '
                .'en el calendario con su fecha y hora de llegada, y se genera '
                .'la cuenta por cobrar por el total cotizado (vence según la '
                .'condición de pago del cliente). Si trabaja más horas de las '
                .'pactadas, el extra se cobra al finalizar.'
            )
            ->modalSubmitActionLabel('Sí, aprobar renta')
            ->action(function (Proyecto $record): void {
                self::ejecutarConManejo(function () use ($record): void {
                    $resultado = app(AprobarRentaService::class)
                        ->aprobar($record, self::userId());

                    $cuenta = $resultado['cuenta'];

                    $cuerpo = $cuenta !== null
                        ? "Cuenta por cobrar {$cuenta->codigo} por L "
                            .number_format((float) $cuenta->monto_original, 2)
                            .' — vence el '.$cuenta->fecha_vencimiento->format('d/m/Y').'.'
                        : 'Sin cuenta por cobrar (total en cero).';

                    Notification::make()
                        ->success()
                        ->title('Renta aprobada y agendada')
                        ->body($cuerpo)
                        ->send();

                    if ($resultado['saltados'] !== []) {
                        Notification::make()
                            ->warning()
                            ->title('Días sin agendar')
                            ->body(
                                implode(' · ', array_slice($resultado['saltados'], 0, 3))
                                .(count($resultado['saltados']) > 3 ? ' · +'.(count($resultado['saltados']) - 3).' más' : '')
                                .' — resolvelos desde el calendario.'
                            )
                            ->persistent()
                            ->send();
                    }
                });
            });
    }

    public static function extender(): Action
    {
        return Action::make('extender_renta')
            ->label('Extender renta')
            ->icon('heroicon-o-plus-circle')
            ->color('warning')
            ->visible(fn (?Proyecto $record): bool => $record instanceof Proyecto
                && $record->esRenta()
                && in_array($record->estado, [EstadoProyecto::Aprobada, EstadoProyecto::EnEjecucion, EstadoProyecto::Pausada], strict: true)
                && self::puede(Permisos::AJUSTAR_PLAZO_PROYECTO))
            ->modalHeading('Extender la renta')
            ->modalDescription(
                'El cliente quiere más horas o días. Se agrega como línea de '
                .'extensión (lo ya pactado no se toca), se agenda en el '
                .'calendario y la cuenta por cobrar sube por el valor extendido.'
            )
            ->modalSubmitActionLabel('Extender')
            ->schema(self::formularioExtension())
            ->action(function (Proyecto $record, array $data): void {
                self::ejecutarConManejo(function () use ($record, $data): void {
                    $resultado = app(ExtenderRentaService::class)->extender(
                        $record,
                        (int) $data['maquina_id'],
                        UnidadRenta::from((string) $data['unidad']),
                        (string) $data['cantidad'],
                        (string) $data['fecha_llegada'],
                        isset($data['hora_llegada']) ? (string) $data['hora_llegada'] : null,
                        isset($data['tarifa']) && $data['tarifa'] !== '' ? (string) $data['tarifa'] : null,
                        null,
                        self::userId(),
                    );

                    Notification::make()
                        ->success()
                        ->title('Renta extendida')
                        ->body($resultado['linea']->etiqueta.' — la cuenta por cobrar ya refleja el aumento.')
                        ->send();

                    if ($resultado['saltados'] !== []) {
                        Notification::make()
                            ->warning()
                            ->title('Días sin agendar')
                            ->body(implode(' · ', $resultado['saltados']).' — resolvelos desde el calendario.')
                            ->persistent()
                            ->send();
                    }
                });
            });
    }

    /**
     * Campos del modal de extensión — mismos que una línea de renta.
     * La tarifa se sugiere sola al elegir máquina/unidad y es ajustable.
     *
     * @return array<int, mixed>
     */
    private static function formularioExtension(): array
    {
        return [
            Select::make('maquina_id')
                ->label('Máquina')
                ->options(
                    fn (): array => Maquina::query()
                        ->activas()
                        ->orderBy('nombre')
                        ->get()
                        ->mapWithKeys(fn (Maquina $m): array => [$m->id => "{$m->codigo} · {$m->nombre}"])
                        ->all()
                )
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn ($state, Get $get, Set $set, ?Proyecto $record) => self::sugerirUnidadYTarifa($state, $get, $set, $record)),

            // La unidad NO arranca fija en Hora (2026-08-16): al elegir
            // la máquina se hereda la de su renta vigente. Arrancar
            // siempre en "Hora" hacía que una extensión de volqueta se
            // cobrara por horas — y encima abría una segunda dimensión
            // de cobro para la misma máquina.
            ToggleButtons::make('unidad')
                ->label('Se cobra por')
                ->options(UnidadRenta::options())
                ->default(UnidadRenta::Hora->value)
                ->inline()
                ->required()
                ->live()
                ->afterStateUpdated(fn ($state, Get $get, Set $set) => self::sugerirTarifa($get('maquina_id'), $get, $set)),

            TextInput::make('cantidad')
                ->label('Cantidad')
                ->numeric()
                // Medio viaje no existe.
                ->step(fn (Get $get): float => self::unidadDe($get('unidad'))->esEntera() ? 1.0 : 0.5)
                ->minValue(fn (Get $get): float => self::unidadDe($get('unidad'))->esEntera() ? 1.0 : 0.5)
                ->required()
                ->suffix(fn (Get $get): string => self::unidadDe($get('unidad'))->sufijoCantidad()),

            TextInput::make('tarifa')
                ->label('Tarifa')
                ->numeric()
                ->step(0.01)
                ->minValue(0)
                ->prefix('L')
                ->suffix(fn (Get $get): string => self::unidadDe($get('unidad'))->sufijoTarifa())
                ->helperText('Se sugiere la del catálogo de la máquina. Ajustable si se pactó otra.'),

            DatePicker::make('fecha_llegada')
                ->label('Día que llega')
                ->required()
                ->default(now())
                ->native(false),

            TimePicker::make('hora_llegada')
                ->label('Hora de llegada')
                ->seconds(false)
                ->native(false),
        ];
    }

    /**
     * La unidad del formulario, siempre como enum (Hora si viene vacía).
     */
    private static function unidadDe(mixed $unidad): UnidadRenta
    {
        return UnidadRenta::tryFrom((string) $unidad) ?? UnidadRenta::Hora;
    }

    /**
     * Al elegir la máquina: hereda la unidad con que YA se le está
     * cobrando en esta renta (o la modalidad de su catálogo si es la
     * primera vez) y recién entonces sugiere la tarifa. Extender por
     * una unidad distinta a la pactada abre una segunda dimensión de
     * cobro para la misma máquina — eso se decide a mano, no por
     * default del formulario.
     */
    private static function sugerirUnidadYTarifa(mixed $maquinaId, Get $get, Set $set, ?Proyecto $proyecto): void
    {
        if ($maquinaId === null || $maquinaId === '') {
            return;
        }

        $vigente = $proyecto instanceof Proyecto
            ? ProyectoLineaRenta::query()
                ->where('proyecto_id', $proyecto->id)
                ->where('maquina_id', (int) $maquinaId)
                ->latest('id')
                ->value('unidad')
            : null;

        $unidad = $vigente !== null
            ? self::unidadDe($vigente)
            : self::unidadSegunMaquina((int) $maquinaId);

        $set('unidad', $unidad->value);

        self::sugerirTarifa($maquinaId, $get, $set);
    }

    /**
     * Sin renta previa de esa máquina: la unidad que sugiere su
     * modalidad de trabajo (volqueta → viajes, pick-up → km).
     */
    private static function unidadSegunMaquina(int $maquinaId): UnidadRenta
    {
        $maquina = Maquina::find($maquinaId);

        if (! $maquina instanceof Maquina) {
            return UnidadRenta::Hora;
        }

        return match ($maquina->modalidad_trabajo) {
            ModalidadTrabajo::Viajes      => UnidadRenta::Viaje,
            ModalidadTrabajo::Kilometraje => UnidadRenta::Kilometro,
            default                       => UnidadRenta::Hora,
        };
    }

    private static function sugerirTarifa(mixed $maquinaId, Get $get, Set $set): void
    {
        if ($maquinaId === null || $maquinaId === '') {
            return;
        }

        $maquina = Maquina::find((int) $maquinaId);

        if (! $maquina instanceof Maquina) {
            return;
        }

        $set('tarifa', self::unidadDe($get('unidad'))->tarifaSugerida($maquina));
    }

    /**
     * Ejecuta y traduce errores de dominio a notificación legible.
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

    private static function puede(string $permiso): bool
    {
        return auth()->user()?->can($permiso) ?? false;
    }

    private static function userId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }
}
