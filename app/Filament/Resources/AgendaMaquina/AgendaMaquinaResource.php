<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgendaMaquina;

use App\Enums\EstadoMaquina;
use App\Enums\EstadoProyecto;
use App\Filament\Forms\Components\RangoFechas;
use App\Filament\Resources\AgendaMaquina\Pages\ManageAgendaMaquina;
use App\Models\AgendaMaquina;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use BackedEnum;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Agenda de máquina — compromisos FUTUROS por día y horas. Se crea desde
 * aquí o desde el botón "Agendar" del calendario (misma forma, mismo
 * service). El calendario la pinta en azul; el parte de trabajo (verde)
 * es la realidad de ese plan.
 */
class AgendaMaquinaResource extends Resource
{
    protected static ?string $model = AgendaMaquina::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'Agendado';

    protected static ?string $pluralModelLabel = 'Agenda';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return 'Maquinaria';
    }

    /**
     * Campos del "Agendar" en LOTE — varias máquinas × rango de días en un
     * solo guardado (30 máquinas diarias: mínimo de clicks). Compartidos
     * entre esta Resource, el botón del calendario y el drag sobre días
     * (única definición, cero duplicación).
     *
     * @return array<int, Component|Field>
     */
    public static function camposAgendar(): array
    {
        return [
            Select::make('maquina_ids')
                ->label('Máquinas')
                ->multiple()
                // Reactivo a las fechas: las máquinas en taller durante el
                // rango aparecen DESHABILITADAS con la razón en el label —
                // el conflicto se ve antes de agendar, no en la notificación.
                // Las que ya tienen compromisos en el rango lo MUESTRAN
                // ("ese día 08:00–16:00 en X") pero siguen seleccionables:
                // pueden caber en otro horario del mismo día.
                ->options(function (Get $get): array {
                    $bloqueos = self::bloqueosPorMantenimiento($get('fechas'));
                    $compromisos = self::compromisosPorAgenda($get('fechas'));

                    return Maquina::query()
                        ->whereNot('estado', EstadoMaquina::Baja)
                        ->orderBy('nombre')
                        ->get(['id', 'nombre'])
                        ->mapWithKeys(fn (Maquina $maquina): array => [
                            $maquina->id => match (true) {
                                isset($bloqueos[$maquina->id])    => "{$maquina->nombre} — {$bloqueos[$maquina->id]}",
                                isset($compromisos[$maquina->id]) => "{$maquina->nombre} — {$compromisos[$maquina->id]}",
                                default                           => $maquina->nombre,
                            },
                        ])
                        ->all();
                })
                ->disableOptionWhen(fn (mixed $value, Get $get): bool => array_key_exists(
                    (int) $value,
                    self::bloqueosPorMantenimiento($get('fechas')),
                ))
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->prefixIcon('heroicon-o-truck')
                ->helperText('Selecciona una o varias — todas van a la misma obra. Las que están en taller aparecen deshabilitadas; las que ya tienen compromisos lo muestran junto al nombre.')
                ->columnSpanFull(),

            Select::make('proyecto_id')
                ->label('Obra destino')
                // Con UNA máquina y UN día elegidos: la obra donde ya está
                // agendada ese día se deshabilita (misma máquina + misma
                // obra + mismo día está prohibido — unique + service). Con
                // varias máquinas no se puede deshabilitar (unas la tienen,
                // otras no): ahí protege el service al guardar.
                ->options(function (Get $get): array {
                    $ocupadas = self::obrasYaAgendadas($get('fechas'), (array) $get('maquina_ids'));

                    return Proyecto::query()
                        ->whereIn('estado', [EstadoProyecto::EnEjecucion->value, EstadoProyecto::Pausada->value])
                        ->orderBy('nombre')
                        ->get(['id', 'nombre'])
                        ->mapWithKeys(fn (Proyecto $proyecto): array => [
                            $proyecto->id => isset($ocupadas[$proyecto->id])
                                ? "{$proyecto->nombre} — {$ocupadas[$proyecto->id]['detalle']}"
                                : $proyecto->nombre,
                        ])
                        ->all();
                })
                ->disableOptionWhen(function (mixed $value, Get $get): bool {
                    $ocupadas = self::obrasYaAgendadas($get('fechas'), (array) $get('maquina_ids'));

                    return (bool) ($ocupadas[(int) $value]['bloquear'] ?? false);
                })
                ->searchable()
                ->preload()
                ->required()
                ->prefixIcon('heroicon-o-map-pin')
                ->columnSpanFull(),

            RangoFechas::make('fechas')
                ->label('Fechas')
                ->required()
                ->rule('array')
                ->live()
                // Si el rango nuevo mete a una máquina ya seleccionada en
                // su mantenimiento, se quita sola de la selección.
                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                    $bloqueadas = array_keys(self::bloqueosPorMantenimiento($state));
                    $seleccion = array_map(intval(...), (array) $get('maquina_ids'));

                    $set('maquina_ids', array_values(array_diff($seleccion, $bloqueadas)));
                })
                ->columnSpanFull(),

            Grid::make(2)
                ->schema([
                    // Hora de LLEGADA a la obra: es la hora del aviso
                    // "confirma la llegada". En 12 horas (AM/PM) — en la
                    // constructora nadie habla en formato de 24. Las horas
                    // trabajadas las dirá la jornada — aquí no se estima.
                    Select::make('hora_entrada')
                        ->label('Hora de llegada')
                        ->options(self::opcionesHoraLlegada())
                        ->searchable()
                        ->prefixIcon('heroicon-o-clock')
                        ->required()
                        ->helperText('A esta hora llegará el aviso de "confirma la llegada".'),

                    Toggle::make('excluir_domingos')
                        ->label('Excluir domingos')
                        ->default(true)
                        ->inline(false)
                        ->visible(function (Get $get): bool {
                            $fechas = $get('fechas');

                            return is_array($fechas) && count($fechas) >= 2 && $fechas[0] !== $fechas[1];
                        }),
                ])
                ->columnSpanFull(),

            Textarea::make('notas')
                ->label('Notas')
                ->rows(2)
                ->maxLength(255)
                ->placeholder('Instrucciones, punto de encuentro…')
                ->columnSpanFull(),
        ];
    }

    /**
     * Máquinas cuyo mantenimiento TOCA el rango de fechas elegido, con la
     * razón legible para el label ("en taller hasta el 17/07"). Memoizado
     * por request con once(): disableOptionWhen lo consulta por cada
     * opción del select y la query debe correr una sola vez.
     *
     * @return array<int, string> [maquina_id => razón]
     */
    public static function bloqueosPorMantenimiento(mixed $fechas): array
    {
        if (! is_array($fechas) || ! is_string($fechas[0] ?? null)) {
            return [];
        }

        $desde = $fechas[0];
        $hasta = is_string($fechas[1] ?? null) ? $fechas[1] : $desde;

        return once(fn (): array => MantenimientoMaquina::query()
            ->whereDate('fecha_inicio', '<=', $hasta)
            ->where(fn (Builder $q) => $q->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $desde))
            ->get(['maquina_id', 'fecha_inicio', 'fecha_fin'])
            ->groupBy('maquina_id')
            ->map(function ($mantenimientos): string {
                $abierto = $mantenimientos->firstWhere('fecha_fin', null);

                if ($abierto !== null) {
                    return 'en taller desde el '.$abierto->fecha_inicio->format('d/m').' (sin fecha de salida)';
                }

                /** @var Carbon $fin */
                $fin = $mantenimientos->max('fecha_fin');

                return 'en taller hasta el '.$fin->format('d/m');
            })
            ->all());
    }

    /**
     * Compromisos de agenda que TOCAN el rango elegido, por máquina, para
     * el label del select. Informativo — NO deshabilita: quien agenda ve
     * los compromisos del día y decide con criterio (la agenda es simple:
     * llegada + obra, sin horas estimadas). Rango de un día: llegadas
     * exactas; varios días: solo el conteo.
     *
     * @return array<int, string> [maquina_id => detalle]
     */
    public static function compromisosPorAgenda(mixed $fechas): array
    {
        if (! is_array($fechas) || ! is_string($fechas[0] ?? null)) {
            return [];
        }

        $desde = $fechas[0];
        $hasta = is_string($fechas[1] ?? null) ? $fechas[1] : $desde;

        return once(fn (): array => AgendaMaquina::query()
            ->with('proyecto:id,nombre')
            ->whereBetween('fecha', [$desde, $hasta])
            ->orderBy('fecha')
            ->orderBy('hora_entrada')
            ->get()
            ->groupBy('maquina_id')
            ->map(function ($agendados) use ($desde, $hasta): string {
                if ($desde !== $hasta) {
                    $dias = $agendados->pluck('fecha')->unique()->count();

                    return "con compromisos en {$dias} día(s) del rango";
                }

                return 'ese día '.$agendados
                    ->map(fn (AgendaMaquina $a): string => $a->hora_entrada !== null
                        ? "llega {$a->horaEntrada12()} a {$a->proyecto->nombre}"
                        : "en {$a->proyecto->nombre}")
                    ->implode(' y ');
            })
            ->all());
    }

    /**
     * Obras donde la máquina elegida YA está agendada dentro del rango.
     * Solo aplica con UNA máquina seleccionada (con varias, unas la tienen
     * y otras no — el service decide al guardar). Rango de un día: se
     * BLOQUEA la opción (duplicado seguro); varios días: solo se informa
     * (los días libres del rango sí se agendan; los tomados se saltan).
     *
     * @return array<int, array{detalle: string, bloquear: bool}>
     */
    public static function obrasYaAgendadas(mixed $fechas, array $maquinaIds): array
    {
        $ids = array_values(array_filter(array_map(intval(...), $maquinaIds)));

        if (count($ids) !== 1 || ! is_array($fechas) || ! is_string($fechas[0] ?? null)) {
            return [];
        }

        $maquinaId = $ids[0];
        $desde = $fechas[0];
        $hasta = is_string($fechas[1] ?? null) ? $fechas[1] : $desde;

        return once(fn (): array => AgendaMaquina::query()
            ->where('maquina_id', $maquinaId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->get(['proyecto_id', 'fecha'])
            ->groupBy('proyecto_id')
            ->map(fn ($agendados): array => $desde === $hasta
                ? ['detalle' => 'la máquina ya está agendada aquí ese día', 'bloquear' => true]
                : ['detalle' => 'ya agendada aquí en '.$agendados->count().' día(s) del rango (se saltan)', 'bloquear' => false])
            ->all());
    }

    /**
     * Horas de llegada cada 30 minutos, guardadas 'H:i' y mostradas en
     * 12 horas ('6:30 AM') — el formato que se maneja en la constructora.
     *
     * @return array<string, string>
     */
    public static function opcionesHoraLlegada(): array
    {
        $opciones = [];

        for ($minutos = 0; $minutos < 24 * 60; $minutos += 30) {
            $hora = sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
            $opciones[$hora] = Carbon::parse($hora)->format('g:i A');
        }

        return $opciones;
    }

    public static function table(Table $table): Table
    {
        return Tables\AgendaMaquinaTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['maquina:id,codigo,nombre', 'proyecto:id,nombre', 'user:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAgendaMaquina::route('/'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['maquina.nombre', 'proyecto.nombre'];
    }
}
