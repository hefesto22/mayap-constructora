<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Enums\EstadoProyecto;
use App\Enums\RubroCostoArranque;
use App\Models\CostoArranqueProyecto;
use App\Models\Proyecto;
use App\Services\Proyectos\RegistrarCostoArranqueService;
use App\Support\Permisos;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * Gasto que la obra ya arrastraba ANTES de entrar al sistema.
 *
 * Solo aparece en obras marcadas como heredadas: en una obra que nació
 * dentro de MAYAP no tiene sentido y sería una puerta para inflar costos.
 *
 * Se carga de a una partida porque el arrastre no se recuerda de una
 * sentada — van apareciendo facturas viejas — y cada una deja asentado de
 * qué era y de cuándo, que es lo único que dentro de tres meses permite
 * validar la cifra.
 */
class CostosArranqueRelationManager extends RelationManager
{
    protected static string $relationship = 'costosArranque';

    protected static ?string $title = 'Gasto anterior al sistema';

    protected static string|BackedEnum|null $icon = 'heroicon-o-archive-box-arrow-down';

    /**
     * Solo obras heredadas y presupuestadas: una renta de maquinaria no
     * arrastra costo de obra, y una obra nacida en el sistema tampoco.
     */
    #[Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Proyecto
            && $ownerRecord->es_obra_heredada
            && ! $ownerRecord->esRenta();
    }

    /**
     * Se congela cuando la obra deja de estar viva o si falta el permiso:
     * este dato toca el margen directamente.
     */
    #[Override]
    public function isReadOnly(): bool
    {
        $owner = $this->getOwnerRecord();

        $estadoVivo = $owner instanceof Proyecto && in_array(
            $owner->estado,
            [EstadoProyecto::Aprobada, EstadoProyecto::EnEjecucion, EstadoProyecto::Pausada],
            strict: true,
        );

        return ! $estadoVivo || ! (auth()->user()?->can(Permisos::REGISTRAR_COSTO_ARRANQUE_PROYECTO) ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('fecha', 'desc')
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/M/Y')
                    ->sortable(),

                TextColumn::make('rubro')
                    ->label('Rubro')
                    ->badge()
                    ->color(fn (RubroCostoArranque $state): string => $state->getColor())
                    ->icon(fn (RubroCostoArranque $state): string => $state->getIcon())
                    ->formatStateUsing(fn (RubroCostoArranque $state): string => $state->getLabel()),

                TextColumn::make('descripcion')
                    ->label('¿De qué era?')
                    ->limit(60)
                    ->tooltip(fn (CostoArranqueProyecto $record): string => $record->descripcion)
                    ->description(fn (CostoArranqueProyecto $record): ?string => $record->registradoPor?->name),

                TextColumn::make('monto')
                    ->label('Monto')
                    ->money('HNL')
                    ->alignEnd()
                    ->weight('bold')
                    ->summarize(Sum::make()->money('HNL')->label('Arrastre total')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Agregar partida')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => ! $this->isReadOnly())
                    ->schema($this->camposPartida())
                    ->using(function (array $data): CostoArranqueProyecto {
                        /** @var Proyecto $owner */
                        $owner = $this->getOwnerRecord();

                        return app(RegistrarCostoArranqueService::class)->agregar(
                            $owner,
                            RubroCostoArranque::from((string) $data['rubro']),
                            (string) $data['monto'],
                            (string) $data['descripcion'],
                            Carbon::parse((string) $data['fecha']),
                            auth()->id(),
                        );
                    }),
            ])
            ->recordActions([
                // Sin edición: una partida mal cargada se borra y se vuelve a
                // agregar, así el historial no queda con cifras reescritas.
                DeleteAction::make()
                    ->modalDescription('La partida se quita del arrastre y el costo real de la obra baja en ese monto.')
                    ->using(function (CostoArranqueProyecto $record): void {
                        app(RegistrarCostoArranqueService::class)->eliminar($record);
                    }),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Sin gasto anterior cargado')
            ->emptyStateDescription('Esta obra figura como heredada pero arranca con costo real 0: el margen va a salir al 100% hasta que se cargue lo ya gastado.');
    }

    /**
     * @return list<Component>
     */
    private function camposPartida(): array
    {
        return [
            Select::make('rubro')
                ->label('Rubro')
                ->required()
                ->options(RubroCostoArranque::options())
                ->default(RubroCostoArranque::Materiales->value)
                ->native(false),

            TextInput::make('monto')
                ->label('Monto')
                ->required()
                ->numeric()
                ->minValue(0.01)
                ->step(0.01)
                ->prefix('L'),

            DatePicker::make('fecha')
                ->label('¿De cuándo es?')
                ->required()
                ->default(now())
                ->native(false),

            Textarea::make('descripcion')
                ->label('¿De qué era?')
                ->required()
                ->rows(2)
                ->maxLength(300)
                ->helperText('Ej: "Hierro 3/8, ferretería El Sol, factura 4412".'),
        ];
    }
}
