<?php

declare(strict_types=1);

namespace App\Filament\Resources\SolicitudesMaquina;

use App\Enums\EstadoMaquina;
use App\Enums\EstadoProyecto;
use App\Enums\EstadoSolicitudMaquina;
use App\Enums\PrioridadSolicitud;
use App\Exceptions\Maquinaria\MaquinariaException;
use App\Filament\Forms\Components\RangoFechas;
use App\Filament\Resources\AgendaMaquina\AgendaMaquinaResource;
use App\Filament\Resources\SolicitudesMaquina\Pages\ManageSolicitudesMaquina;
use App\Filament\Resources\SolicitudesMaquina\Pages\ViewSolicitudMaquina;
use App\Models\AgendaMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\SolicitudMaquina;
use App\Models\User;
use App\Services\Maquinaria\SolicitarMaquinaService;
use App\Support\Roles;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Solicitudes de maquinaria — el encargado pide "esta máquina para tal
 * día a tal hora" desde SU obra; la agenda decide al instante (disponible
 * → Agendada; ocupada → Pendiente para el rol maquinaria). Historial del
 * proyecto: nunca se borran.
 */
class SolicitudMaquinaResource extends Resource
{
    protected static ?string $model = SolicitudMaquina::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?string $recordTitleAttribute = 'codigo';

    protected static ?string $modelLabel = 'Solicitud de máquina';

    protected static ?string $pluralModelLabel = 'Solicitudes';

    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): ?string
    {
        return 'Maquinaria';
    }

    /**
     * Campos del "Solicitar máquina". La disponibilidad se ve EN el form:
     * máquinas en taller deshabilitadas con razón, compromisos del día
     * junto al nombre (mismos helpers del Agendar — única definición).
     *
     * @return array<int, mixed>
     */
    public static function camposSolicitar(): array
    {
        return [
            Select::make('proyecto_id')
                ->label('Obra (proyecto)')
                ->options(function (): array {
                    $query = Proyecto::query()
                        ->where('estado', EstadoProyecto::EnEjecucion->value)
                        ->orderBy('nombre');

                    $user = auth()->user();

                    // El encargado solo pide para SUS obras.
                    if ($user instanceof User && Roles::soloEncargado($user)) {
                        $query->whereHas('encargados', fn (Builder $q): Builder => $q->whereKey($user->id));
                    }

                    return $query->pluck('nombre', 'id')->all();
                })
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->prefixIcon('heroicon-o-map-pin')
                ->helperText('Solo obras en ejecución. La solicitud queda en el historial del proyecto.')
                ->columnSpanFull(),

            // Mismo selector de RANGO del calendario (un día o "del 17 al
            // 19"): click en el primer día, click en el último.
            RangoFechas::make('fechas')
                ->label('Fechas necesarias en obra')
                ->required()
                ->rule('array')
                ->live()
                ->columnSpanFull(),

            Select::make('hora_llegada')
                ->label('Hora de llegada')
                ->options(AgendaMaquinaResource::opcionesHoraLlegada())
                ->default('08:00')
                ->searchable()
                ->required()
                ->prefixIcon('heroicon-o-clock')
                ->helperText('A esta hora llegará el aviso de "confirma la llegada".'),

            Select::make('maquina_id')
                ->label('Máquina')
                // Disponibilidad a la vista: en taller = deshabilitada con
                // razón; con compromisos ese día = lo dice junto al nombre.
                ->options(function (Get $get): array {
                    $fechas = $get('fechas');

                    $bloqueos = AgendaMaquinaResource::bloqueosPorMantenimiento($fechas);
                    $compromisos = AgendaMaquinaResource::compromisosPorAgenda($fechas);

                    return Maquina::query()
                        ->whereNot('estado', EstadoMaquina::Baja)
                        // Ya agendada a ESTA obra en esas fechas: ni se
                        // muestra — pedirla otra vez no tiene sentido, ya
                        // la tienen.
                        ->whereNotIn('id', self::maquinasYaEnLaObra($fechas, $get('proyecto_id')))
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
                    AgendaMaquinaResource::bloqueosPorMantenimiento($get('fechas')),
                ))
                ->searchable()
                ->preload()
                ->required()
                ->live()
                // Misma máquina + misma obra + mismo día: no es una
                // solicitud, es un error — se marca aquí, sin crear nada.
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $fechas = array_values((array) $get('fechas'));
                        $proyectoId = $get('proyecto_id');

                        if (! is_numeric($value) || ! is_numeric($proyectoId) || ! is_string($fechas[0] ?? null)) {
                            return;
                        }

                        $ocupada = AgendaMaquina::query()
                            ->where('maquina_id', (int) $value)
                            ->where('proyecto_id', (int) $proyectoId)
                            ->whereBetween('fecha', [$fechas[0], $fechas[1] ?? $fechas[0]])
                            ->exists();

                        if ($ocupada) {
                            $fail('Esta máquina ya está agendada a esa obra en esas fechas — no se llama dos veces a la misma obra el mismo día.');
                        }
                    },
                ])
                ->prefixIcon('heroicon-o-truck')
                ->helperText('Día libre = se agenda al instante. Si ya tiene un compromiso ese día (o está en taller), maquinaria autoriza o resuelve la solicitud.')
                ->columnSpanFull(),

            // Lo URGENTE ("sí o sí se necesita") le llega a maquinaria
            // marcado, para que reorganice el orden si hace falta.
            ToggleButtons::make('prioridad')
                ->label('Prioridad')
                ->options(PrioridadSolicitud::class)
                ->default(PrioridadSolicitud::Normal->value)
                ->inline()
                ->grouped()
                ->columnSpanFull(),

            Textarea::make('notas')
                ->label('Notas')
                ->rows(2)
                ->maxLength(255)
                ->placeholder('Para qué se necesita, punto de encuentro…')
                ->columnSpanFull(),
        ];
    }

    /**
     * Máquinas YA agendadas a la obra elegida dentro del rango: se
     * excluyen del listado — pedirlas otra vez a la misma obra el mismo
     * día no tiene sentido (ya las tienen). Memoizado con once().
     *
     * @return list<int>
     */
    public static function maquinasYaEnLaObra(mixed $fechas, mixed $proyectoId): array
    {
        if (! is_numeric($proyectoId) || ! is_array($fechas) || ! is_string($fechas[0] ?? null)) {
            return [];
        }

        $desde = $fechas[0];
        $hasta = is_string($fechas[1] ?? null) ? $fechas[1] : $desde;
        $proyectoId = (int) $proyectoId;

        // array_values: PHPStan exige list<int> (llaves contiguas), no
        // el array<int, int> genérico que devuelve la colección.
        return once(fn (): array => array_values(AgendaMaquina::query()
            ->where('proyecto_id', $proyectoId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->distinct()
            ->pluck('maquina_id')
            ->map(fn ($id): int => (int) $id)
            ->all()));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('fecha_necesaria')
                    ->label('Para')
                    ->formatStateUsing(fn (SolicitudMaquina $record): string => $record->rangoParaEl())
                    ->sortable(),

                TextColumn::make('hora_llegada')
                    ->label('Llegada')
                    ->formatStateUsing(fn (?string $state): string => $state !== null
                        ? Carbon::parse($state)->format('g:i A')
                        : '—'),

                TextColumn::make('maquina.nombre')
                    ->label('Máquina')
                    ->searchable(),

                TextColumn::make('proyecto.nombre')
                    ->label('Obra')
                    ->searchable()
                    ->limit(30),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge(),

                TextColumn::make('prioridad')
                    ->label('Prioridad')
                    ->badge()
                    ->sortable(),

                TextColumn::make('solicitante.name')
                    ->label('Solicitó')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('motivo')
                    ->label('Resolución')
                    ->limit(45)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoSolicitudMaquina::class),
            ])
            ->recordActions(self::accionesResolver())
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Sin solicitudes de maquinaria')
            ->emptyStateDescription('El encargado de obra pide aquí la máquina que necesita; si está libre ese día, se agenda sola.')
            ->paginated([25, 50]);
    }

    /**
     * Resolución del rol maquinaria (compartida entre la tabla y la vista
     * de la solicitud): agendarla — misma máquina u otra, mismas fechas u
     * otras — o rechazarla con motivo. Los choques revalidan en el service.
     *
     * @return array<int, Action>
     */
    public static function accionesResolver(): array
    {
        return [
            Action::make('agendar')
                ->label('Agendar')
                ->icon('heroicon-o-calendar-days')
                ->color('success')
                ->visible(fn (SolicitudMaquina $record): bool => $record->estado->esPendiente()
                    && (auth()->user()?->can('Update:SolicitudMaquina') ?? false))
                ->schema([
                    RangoFechas::make('fechas')
                        ->label('Fechas')
                        ->required()
                        ->rule('array')
                        ->default(fn (SolicitudMaquina $record): array => array_values(array_filter([
                            $record->fecha_necesaria->toDateString(),
                            $record->fecha_hasta?->toDateString(),
                        ]))),

                    Select::make('maquina_id')
                        ->label('Máquina (puede ser otra)')
                        ->options(fn () => Maquina::query()
                            ->whereNot('estado', EstadoMaquina::Baja)
                            ->orderBy('nombre')
                            ->pluck('nombre', 'id'))
                        ->default(fn (SolicitudMaquina $record): int => $record->maquina_id)
                        ->searchable()
                        ->required(),

                    Select::make('hora_llegada')
                        ->label('Hora de llegada')
                        ->options(AgendaMaquinaResource::opcionesHoraLlegada())
                        ->default(fn (SolicitudMaquina $record): string => substr((string) $record->hora_llegada, 0, 5))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (SolicitudMaquina $record, array $data): void {
                    $fechas = array_values((array) ($data['fechas'] ?? []));

                    try {
                        app(SolicitarMaquinaService::class)->agendar(
                            solicitud: $record,
                            fechaDesde: (string) ($fechas[0] ?? today()->toDateString()),
                            fechaHasta: isset($fechas[1]) ? (string) $fechas[1] : null,
                            maquinaId: (int) $data['maquina_id'],
                            horaLlegada: (string) $data['hora_llegada'],
                            userId: is_numeric(auth()->id()) ? (int) auth()->id() : null,
                        );
                    } catch (MaquinariaException $e) {
                        Notification::make()
                            ->title('No se pudo agendar')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Solicitud agendada')
                        ->success()
                        ->send();
                }),

            Action::make('rechazar')
                ->label('Rechazar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (SolicitudMaquina $record): bool => $record->estado->esPendiente()
                    && (auth()->user()?->can('Update:SolicitudMaquina') ?? false))
                ->schema([
                    Textarea::make('motivo')
                        ->label('Motivo del rechazo')
                        ->rows(2)
                        ->required()
                        ->helperText('El solicitante lo recibe en su campanita.'),
                ])
                ->action(function (SolicitudMaquina $record, array $data): void {
                    try {
                        app(SolicitarMaquinaService::class)->rechazar(
                            solicitud: $record,
                            motivo: (string) $data['motivo'],
                            userId: is_numeric(auth()->id()) ? (int) auth()->id() : null,
                        );
                    } catch (MaquinariaException $e) {
                        Notification::make()
                            ->title('No se pudo rechazar')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Solicitud rechazada')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Vista de UNA solicitud — a donde aterriza la campanita: qué se
     * pidió, para cuándo, en qué quedó y quién la resolvió.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Solicitud de máquina')
                ->icon('heroicon-o-hand-raised')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('codigo')
                            ->label('Código')
                            ->weight('bold')
                            ->copyable(),

                        TextEntry::make('maquina.nombre')
                            ->label('Máquina'),

                        TextEntry::make('proyecto.nombre')
                            ->label('Obra'),

                        TextEntry::make('fecha_necesaria')
                            ->label('Para')
                            ->formatStateUsing(fn (SolicitudMaquina $record): string => $record->rangoParaEl()),

                        TextEntry::make('hora_llegada')
                            ->label('Hora de llegada')
                            ->formatStateUsing(fn (SolicitudMaquina $record): string => $record->horaLlegada12() ?? '—'),

                        TextEntry::make('estado')
                            ->label('Estado')
                            ->badge(),

                        TextEntry::make('prioridad')
                            ->label('Prioridad')
                            ->badge(),

                        TextEntry::make('solicitante.name')
                            ->label('Solicitó')
                            ->placeholder('—'),

                        TextEntry::make('created_at')
                            ->label('Solicitada el')
                            ->dateTime('d/M/Y g:i A'),

                        TextEntry::make('resueltaPor.name')
                            ->label('Resolvió')
                            ->placeholder('—'),

                        TextEntry::make('motivo')
                            ->label('Resolución')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('notas')
                            ->label('Notas')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['maquina:id,codigo,nombre', 'proyecto:id,nombre', 'solicitante:id,name']);

        $user = auth()->user();

        // El encargado solo ve las solicitudes de SUS obras.
        if ($user instanceof User && Roles::soloEncargado($user)) {
            $query->whereHas('proyecto.encargados', fn (Builder $q): Builder => $q->whereKey($user->id));
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSolicitudesMaquina::route('/'),
            'view'  => ViewSolicitudMaquina::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'maquina.nombre', 'proyecto.nombre'];
    }
}
