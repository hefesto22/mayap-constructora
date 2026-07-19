<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\RelationManagers;

use App\Enums\AlertaMantenimiento;
use App\Exceptions\Maquinaria\MantenimientoPreventivoInvalidoException;
use App\Models\Maquina;
use App\Models\PlanMantenimiento;
use App\Services\Maquinaria\RegistrarCambioMantenimientoService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Planes de mantenimiento preventivo de la máquina — "cambio de aceite
 * cada 250 h o 90 días", "puntas cada 400 h", "aceite de volqueta cada
 * 5,000 km". Cada plan combina horas / km / días y avisa por LO QUE
 * LLEGUE PRIMERO (90% = próximo, 100% = vencido).
 *
 * El botón "Registrar cambio" anota que el taller YA lo hizo: deja
 * historial, resetea el contador y rearma la campanita (vía
 * RegistrarCambioMantenimientoService, la única puerta).
 */
class PlanesMantenimientoRelationManager extends RelationManager
{
    protected static string $relationship = 'planesMantenimiento';

    protected static ?string $title = 'Mantenimiento preventivo';

    protected static string|BackedEnum|null $icon = 'heroicon-o-wrench-screwdriver';

    /** Sugerencias rápidas — lo que Mauricio pidió, sin cerrar la lista. */
    private const array SUGERENCIAS = [
        'CAMBIO DE ACEITE',
        'CAMBIO DE PUNTAS',
        'CAMBIO DE CUCHILLAS',
        'CAMBIO DE FILTROS',
    ];

    public function form(Schema $schema): Schema
    {
        $owner = $this->getOwnerRecord();
        $maquina = $owner instanceof Maquina ? $owner : null;

        return $schema->components([
            TextInput::make('nombre')
                ->label('Mantenimiento')
                ->required()
                ->maxLength(100)
                ->placeholder('CAMBIO DE ACEITE')
                ->datalist(self::SUGERENCIAS)
                ->mayusculas()
                ->columnSpanFull(),

            TextInput::make('frecuencia_horas')
                ->label('Cada (horas de horómetro)')
                ->numeric()
                ->minValue(0.01)
                ->step('any')
                ->suffix('h')
                ->requiredWithoutAll(['frecuencia_km', 'frecuencia_dias'])
                ->validationMessages([
                    'required_without_all' => 'Define al menos una frecuencia: horas, km o días.',
                ])
                ->helperText('Ej: 250. Se compara contra el horómetro que mueven los partes de trabajo.'),

            TextInput::make('frecuencia_km')
                ->label('Cada (kilómetros)')
                ->numeric()
                ->minValue(0.01)
                ->step('any')
                ->suffix('km')
                ->helperText('Ej: 5,000. Requiere mantener el kilometraje de la máquina al día (lectura manual).'),

            TextInput::make('frecuencia_dias')
                ->label('Cada (días)')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->suffix('días')
                ->helperText('Ej: 90 (3 meses). Corre por calendario aunque la máquina no trabaje.'),

            DatePicker::make('fecha_ultimo_cambio')
                ->label('Fecha del último cambio')
                ->default(now())
                ->maxDate(now())
                ->required()
                ->native(false)
                ->helperText('Si nunca se ha hecho, deja hoy: el conteo arranca desde ahora.'),

            TextInput::make('horometro_ultimo_cambio')
                ->label('Horómetro en el último cambio')
                ->numeric()
                ->minValue(0)
                ->step('any')
                ->suffix('h')
                ->default(fn (): ?string => $maquina !== null ? (string) $maquina->horometro_actual : null)
                ->helperText('Lectura del reloj cuando se hizo. Por defecto: la actual.'),

            TextInput::make('km_ultimo_cambio')
                ->label('Km en el último cambio')
                ->numeric()
                ->minValue(0)
                ->step('any')
                ->suffix('km')
                ->default(fn (): ?string => $maquina?->kilometraje_actual !== null
                    ? (string) $maquina->kilometraje_actual
                    : null),

            Toggle::make('activo')
                ->label('Plan activo')
                ->default(true)
                ->onColor('success')
                ->offColor('danger')
                ->helperText('Los planes inactivos no alertan ni mandan campanita.'),

            TextInput::make('notas')
                ->label('Notas')
                ->maxLength(255)
                ->mayusculas(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('maquina'))
            ->columns([
                TextColumn::make('nombre')
                    ->label('Mantenimiento')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('intervalo')
                    ->label('Intervalo')
                    ->state(fn (PlanMantenimiento $record): string => $record->intervaloResumen())
                    ->color('gray'),

                TextColumn::make('fecha_ultimo_cambio')
                    ->label('Último cambio')
                    ->date('d/M/Y'),

                TextColumn::make('uso')
                    ->label('Uso desde el cambio')
                    ->state(fn (PlanMantenimiento $record): string => $record->usoResumen()),

                TextColumn::make('alerta')
                    ->label('Alerta')
                    ->badge()
                    ->state(fn (PlanMantenimiento $record): string => $record->estadoAlerta()->getLabel())
                    ->color(fn (PlanMantenimiento $record): string => $record->estadoAlerta()->getColor())
                    ->icon(fn (PlanMantenimiento $record): string => $record->estadoAlerta()->getIcon()),

                ToggleColumn::make('activo')
                    ->label('Activo')
                    ->onColor('success')
                    ->offColor('danger'),
            ])
            ->headerActions([
                CreateAction::make()->label('Nuevo plan'),
            ])
            ->recordActions([
                $this->accionRegistrarCambio(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * "Ya se hizo el cambio" — captura fecha y lecturas del momento y
     * reinicia el contador del plan.
     */
    private function accionRegistrarCambio(): Action
    {
        return Action::make('registrar_cambio')
            ->label('Registrar cambio')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (PlanMantenimiento $record): bool => $record->activo
                && (auth()->user()?->can('update', $record) ?? false))
            ->modalHeading(fn (PlanMantenimiento $record): string => 'Registrar: '.$record->nombre)
            ->modalDescription('Anota que el cambio YA se hizo: queda en el historial y el contador arranca de cero.')
            ->modalSubmitActionLabel('Registrar')
            ->schema(fn (PlanMantenimiento $record): array => [
                DatePicker::make('fecha')
                    ->label('Fecha del cambio')
                    ->default(now())
                    ->maxDate(now())
                    ->required()
                    ->native(false),

                TextInput::make('horometro')
                    ->label('Horómetro al hacerlo')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->suffix('h')
                    ->default((string) $record->maquina->horometro_actual)
                    ->visible($record->frecuencia_horas !== null),

                TextInput::make('kilometraje')
                    ->label('Kilometraje al hacerlo')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->suffix('km')
                    ->default($record->maquina->kilometraje_actual !== null
                        ? (string) $record->maquina->kilometraje_actual
                        : null)
                    ->visible($record->frecuencia_km !== null)
                    ->helperText('Si es mayor que el registrado en la máquina, también se actualiza allá.'),

                TextInput::make('notas')
                    ->label('Notas')
                    ->maxLength(255)
                    ->mayusculas(),
            ])
            ->action(function (PlanMantenimiento $record, array $data): void {
                $userId = auth()->id();
                $userId = is_numeric($userId) ? (int) $userId : null;

                try {
                    app(RegistrarCambioMantenimientoService::class)->registrar(
                        plan: $record,
                        fecha: (string) $data['fecha'],
                        horometro: isset($data['horometro']) && $data['horometro'] !== ''
                            ? (string) $data['horometro']
                            : null,
                        kilometraje: isset($data['kilometraje']) && $data['kilometraje'] !== ''
                            ? (string) $data['kilometraje']
                            : null,
                        notas: isset($data['notas']) && $data['notas'] !== ''
                            ? (string) $data['notas']
                            : null,
                        userId: $userId,
                    );
                } catch (MantenimientoPreventivoInvalidoException $e) {
                    Notification::make()
                        ->title('No se pudo registrar el cambio')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $record->refresh()->load('maquina');

                Notification::make()
                    ->success()
                    ->title('Cambio registrado')
                    ->body($record->estadoAlerta() === AlertaMantenimiento::AlDia
                        ? "{$record->nombre} al día: el contador arrancó de cero."
                        : "{$record->nombre} registrado — ojo: con las lecturas capturadas sigue en "
                            .mb_strtolower($record->estadoAlerta()->getLabel()).'.')
                    ->send();
            });
    }
}
