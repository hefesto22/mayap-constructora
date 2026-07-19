<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Enums\UnidadRenta;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\ProyectoLineaRenta;
use App\Services\Proyectos\AgregarLineaRentaService;
use App\Services\Proyectos\CalcularPrecioProyectoService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Líneas de renta del proyecto (solo tipo renta_maquinaria): máquina ×
 * cantidad (horas o días) × tarifa, con SU fecha y hora de llegada.
 * El espejo liviano de la composición por renglones.
 *
 * Solo editable en Borrador. Después de aprobar, los cambios entran
 * por la acción "Extender renta" (líneas marcadas como extensión) —
 * lo pactado nunca se edita.
 */
class LineasRentaRelationManager extends RelationManager
{
    protected static string $relationship = 'lineasRenta';

    protected static ?string $title = 'Máquinas rentadas';

    protected static string|BackedEnum|null $icon = 'heroicon-o-truck';

    /**
     * Solo visible en proyectos tipo renta.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Proyecto && $ownerRecord->esRenta();
    }

    /**
     * Solo se pueden tocar líneas en Borrador; después la tabla queda
     * de solo lectura y las extensiones entran por su acción.
     */
    public function isReadOnly(): bool
    {
        $owner = $this->getOwnerRecord();

        return ! ($owner instanceof Proyecto && $owner->estado->permiteEditar());
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('maquina'))
            ->reorderable('orden')
            ->defaultSort('orden')
            ->columns([
                TextColumn::make('maquina.codigo')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->searchable(),

                TextColumn::make('maquina.nombre')
                    ->label('Máquina')
                    ->wrap()
                    ->limit(50)
                    ->searchable(),

                TextColumn::make('unidad')
                    ->label('Por')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                TextColumn::make('tarifa_snapshot')
                    ->label('Tarifa')
                    ->money('HNL')
                    ->alignEnd(),

                TextColumn::make('subtotal_cache')
                    ->label('Subtotal')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('HNL')->label('Subtotal')),

                TextColumn::make('fecha_llegada')
                    ->label('Llega')
                    ->date('d/M/Y'),

                TextColumn::make('hora_llegada')
                    ->label('Hora')
                    ->formatStateUsing(fn (ProyectoLineaRenta $record): string => $record->horaLlegadaCorta() ?? '—'),

                IconColumn::make('es_extension')
                    ->label('Extensión')
                    ->boolean()
                    ->trueIcon('heroicon-o-plus-circle')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning')
                    ->falseColor('gray'),
            ])
            ->headerActions([
                $this->accionAgregarMaquina(),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        TextInput::make('cantidad')
                            ->label('Cantidad')
                            ->numeric()
                            ->step(0.5)
                            ->minValue(0.5)
                            ->required(),
                        TextInput::make('tarifa_snapshot')
                            ->label('Tarifa')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->prefix('L')
                            ->required(),
                        DatePicker::make('fecha_llegada')
                            ->label('Día que llega')
                            ->required()
                            ->native(false),
                        TimePicker::make('hora_llegada')
                            ->label('Hora de llegada')
                            ->seconds(false)
                            ->native(false),
                        TextInput::make('notas')->label('Notas')->maxLength(255),
                    ])
                    ->after(function (): void {
                        $this->recalcular();
                    }),
                DeleteAction::make()
                    ->after(function (): void {
                        $this->recalcular();
                    }),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Sin máquinas todavía')
            ->emptyStateDescription('Agregá la máquina, sus horas o días, y qué día llega. La tarifa se sugiere sola del catálogo.');
    }

    /**
     * Agregar una máquina a la renta — la tarifa se sugiere sola al
     * elegir máquina y unidad (ajustable si se pactó otra).
     */
    private function accionAgregarMaquina(): CreateAction
    {
        return CreateAction::make()
            ->label('Agregar máquina')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => ! $this->isReadOnly())
            ->schema([
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
                    ->afterStateUpdated(fn ($state, Get $get, Set $set) => $this->sugerirTarifa($state, $get, $set)),

                ToggleButtons::make('unidad')
                    ->label('Se cobra por')
                    ->options(UnidadRenta::options())
                    ->default(UnidadRenta::Hora->value)
                    ->inline()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, Get $get, Set $set) => $this->sugerirTarifa($get('maquina_id'), $get, $set)),

                TextInput::make('cantidad')
                    ->label('Cantidad')
                    ->numeric()
                    ->step(0.5)
                    ->minValue(0.5)
                    ->required()
                    ->suffix(fn (Get $get): string => $get('unidad') === UnidadRenta::Dia->value ? 'días' : 'horas'),

                TextInput::make('tarifa')
                    ->label('Tarifa')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->prefix('L')
                    ->suffix(fn (Get $get): string => $get('unidad') === UnidadRenta::Dia->value ? 'por día' : 'por hora')
                    ->helperText('Se sugiere la del catálogo. Ajustable si se pactó otra.'),

                DatePicker::make('fecha_llegada')
                    ->label('Día que llega')
                    ->required()
                    ->default(now())
                    ->native(false),

                TimePicker::make('hora_llegada')
                    ->label('Hora de llegada')
                    ->seconds(false)
                    ->native(false),

                TextInput::make('notas')->label('Notas')->maxLength(255),
            ])
            ->using(function (array $data): ProyectoLineaRenta {
                $owner = $this->getOwnerRecord();

                /** @var Proyecto $owner */
                return app(AgregarLineaRentaService::class)->agregar(
                    $owner,
                    (int) $data['maquina_id'],
                    UnidadRenta::from((string) $data['unidad']),
                    (string) $data['cantidad'],
                    (string) $data['fecha_llegada'],
                    isset($data['hora_llegada']) ? (string) $data['hora_llegada'] : null,
                    isset($data['tarifa']) && $data['tarifa'] !== '' ? (string) $data['tarifa'] : null,
                    isset($data['notas']) && $data['notas'] !== '' ? (string) $data['notas'] : null,
                );
            })
            ->after(function (): void {
                $this->recalcular();
            });
    }

    private function sugerirTarifa(mixed $maquinaId, Get $get, Set $set): void
    {
        if ($maquinaId === null || $maquinaId === '') {
            return;
        }

        $maquina = Maquina::find((int) $maquinaId);

        if (! $maquina instanceof Maquina) {
            return;
        }

        $unidad = UnidadRenta::tryFrom((string) $get('unidad')) ?? UnidadRenta::Hora;

        $set('tarifa', $unidad->tarifaSugerida($maquina));
    }

    private function recalcular(): void
    {
        $owner = $this->getOwnerRecord();

        if ($owner instanceof Proyecto) {
            app(CalcularPrecioProyectoService::class)->recalcular($owner);
        }
    }
}
