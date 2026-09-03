<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\RelationManagers;

use App\Filament\Resources\Proyectos\Support\OpcionesFicha;
use App\Models\Ficha;
use App\Models\Proyecto;
use App\Models\ProyectoRenglon;
use App\Services\Proyectos\AgregarRenglonAProyectoService;
use App\Services\Proyectos\CalcularPrecioProyectoService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Renglones del proyecto como TABLA PAGINADA — escala a cientos de líneas
 * sin que la pantalla se haga interminable. Vive en la página de edición
 * (no en crear): primero se crea el encabezado del proyecto, luego acá se
 * cargan las fichas, idealmente con "Carga masiva".
 *
 * Solo editable mientras el proyecto está en Borrador. Cualquier cambio
 * (agregar / editar cantidad / borrar) recalcula los totales del proyecto.
 */
class RenglonesRelationManager extends RelationManager
{
    protected static string $relationship = 'renglones';

    protected static ?string $title = 'Composición (renglones)';

    protected static string|BackedEnum|null $icon = 'heroicon-o-squares-plus';

    /**
     * Solo en proyectos presupuestados: las rentas de maquinaria usan
     * su propio manager de líneas (LineasRentaRelationManager).
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Proyecto && ! $ownerRecord->esRenta();
    }

    /**
     * Solo se pueden tocar renglones en Borrador. En otros estados la
     * tabla queda de solo lectura (se preserva la integridad comercial).
     */
    public function isReadOnly(): bool
    {
        $owner = $this->getOwnerRecord();

        return ! ($owner instanceof Proyecto && $owner->estado->permiteEditar());
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('ficha.unidadMedida'))
            ->reorderable('orden')
            ->defaultSort('orden')
            ->columns([
                TextColumn::make('ficha.codigo')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->searchable(),

                TextColumn::make('ficha.nombre')
                    ->label('Ficha APU')
                    ->wrap()
                    ->limit(60)
                    ->searchable(),

                TextColumn::make('ficha.unidadMedida.codigo')
                    ->label('Unidad')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('capitulo')
                    ->label('Capítulo')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('cantidad')
                    ->label('Cantidad')
                    ->numeric(decimalPlaces: 4)
                    ->alignEnd(),

                TextColumn::make('precio_unitario_snapshot')
                    ->label('P. Unitario')
                    ->money('HNL')
                    ->alignEnd(),

                TextColumn::make('subtotal_cache')
                    ->label('Subtotal')
                    ->money('HNL')
                    ->weight('bold')
                    ->alignEnd()
                    ->summarize(Sum::make()->money('HNL')->label('Subtotal')),
            ])
            ->headerActions([
                $this->accionCargaMasiva(),
                $this->accionAgregarUno(),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        TextInput::make('capitulo')->label('Capítulo (opcional)')->maxLength(100),
                        TextInput::make('cantidad')->label('Cantidad')->numeric()->step(0.0001)->minValue(0.0001)->required(),
                        TextInput::make('notas')->label('Notas')->maxLength(500),
                    ])
                    ->after(function (): void {
                        $this->recalcular();
                    }),
                DeleteAction::make()
                    ->after(function (): void {
                        $this->recalcular();
                    }),
            ])
            ->paginated([10, 25, 50, 100])
            ->emptyStateHeading('Sin renglones todavía')
            ->emptyStateDescription('Usá "Carga masiva" para agregar varias fichas con su cantidad de una sola vez.');
    }

    /**
     * Carga masiva: muchas fichas con cantidad en un solo modal.
     */
    private function accionCargaMasiva(): Action
    {
        return Action::make('carga_masiva')
            ->label('Carga masiva')
            ->icon('heroicon-o-rectangle-stack')
            ->visible(fn (): bool => ! $this->isReadOnly())
            ->modalHeading('Carga masiva de renglones')
            ->modalDescription('Elegí cada ficha y su cantidad. Al confirmar se agregan todas de una vez.')
            ->modalSubmitActionLabel('Agregar todas')
            ->schema([
                Repeater::make('fichas')
                    ->hiddenLabel()
                    ->addActionLabel('+ Otra ficha')
                    ->defaultItems(1)
                    ->minItems(1)
                    ->table([
                        TableColumn::make('Ficha APU'),
                        TableColumn::make('Cantidad')->width('140px'),
                    ])
                    ->schema([
                        Select::make('ficha_id')
                            ->hiddenLabel()
                            // live: al elegir una ficha desaparece de las OTRAS
                            // filas del modal, así no se cargan dos renglones
                            // iguales por descuido.
                            ->live()
                            ->options(fn (Get $get): array => OpcionesFicha::paraZona(
                                $this->zonaId(),
                                $this->fichasTomadas($get),
                            ))
                            ->searchable()
                            ->required(),
                        TextInput::make('cantidad')
                            ->hiddenLabel()
                            ->numeric()
                            ->step(0.0001)
                            ->minValue(0.0001)
                            ->required(),
                    ]),
            ])
            ->action(function (array $data): void {
                $owner = $this->getOwnerRecord();

                if (! $owner instanceof Proyecto) {
                    return;
                }

                $service = app(AgregarRenglonAProyectoService::class);
                $agregados = 0;

                foreach ((array) ($data['fichas'] ?? []) as $fila) {
                    if (! is_array($fila)) {
                        continue;
                    }

                    $ficha = Ficha::find((int) ($fila['ficha_id'] ?? 0));
                    $cantidad = (string) ($fila['cantidad'] ?? '');

                    if ($ficha instanceof Ficha && $cantidad !== '') {
                        $service->ejecutar($owner, $ficha, $cantidad);
                        $agregados++;
                    }
                }

                $this->recalcular();

                Notification::make()->success()->title("{$agregados} renglones agregados")->send();
            });
    }

    /**
     * Agregar un solo renglón (modal corto).
     */
    private function accionAgregarUno(): Action
    {
        return CreateAction::make()
            ->label('Agregar renglón')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => ! $this->isReadOnly())
            ->schema([
                Select::make('ficha_id')
                    ->label('Ficha APU')
                    ->options(fn (): array => OpcionesFicha::paraZona(
                        $this->zonaId(),
                        $this->fichasDelProyecto(),
                    ))
                    ->searchable()
                    ->required(),
                TextInput::make('cantidad')
                    ->label('Cantidad')
                    ->numeric()
                    ->step(0.0001)
                    ->minValue(0.0001)
                    ->required(),
                TextInput::make('capitulo')->label('Capítulo (opcional)')->maxLength(100),
                TextInput::make('notas')->label('Notas')->maxLength(500),
            ])
            ->using(function (array $data): ProyectoRenglon {
                $owner = $this->getOwnerRecord();
                $ficha = Ficha::findOrFail((int) $data['ficha_id']);

                /** @var Proyecto $owner */
                return app(AgregarRenglonAProyectoService::class)->ejecutar(
                    $owner,
                    $ficha,
                    (string) $data['cantidad'],
                    isset($data['capitulo']) ? (string) $data['capitulo'] : null,
                    isset($data['notas']) ? (string) $data['notas'] : null,
                );
            })
            ->after(function (): void {
                $this->recalcular();
            });
    }

    /**
     * Fichas que NO se deben volver a ofrecer en una fila del repeater:
     * las que ya son renglón del proyecto + las elegidas en las OTRAS filas
     * del mismo modal. La propia se conserva para que el select no pierda
     * su valor al re-renderizar.
     *
     * @return list<int>
     */
    private function fichasTomadas(Get $get): array
    {
        $propia = self::aId($get('ficha_id'));

        $enElModal = array_map(
            static fn (mixed $fila): int => is_array($fila) ? self::aId($fila['ficha_id'] ?? null) : 0,
            array_values((array) $get('../../fichas')),
        );

        return array_values(array_filter(
            [...$this->fichasDelProyecto(), ...$enElModal],
            static fn (int $id): bool => $id > 0 && $id !== $propia,
        ));
    }

    /**
     * IDs de las fichas que ya tienen renglón en este proyecto.
     *
     * @return list<int>
     */
    private function fichasDelProyecto(): array
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Proyecto) {
            return [];
        }

        return array_values(
            $owner->renglones()
                ->pluck('ficha_id')
                ->map(static fn (mixed $id): int => self::aId($id))
                ->all()
        );
    }

    private static function aId(mixed $valor): int
    {
        return is_numeric($valor) ? (int) $valor : 0;
    }

    private function zonaId(): ?int
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Proyecto ? $owner->zona_id : null;
    }

    private function recalcular(): void
    {
        $owner = $this->getOwnerRecord();

        if ($owner instanceof Proyecto) {
            app(CalcularPrecioProyectoService::class)->recalcular($owner);
        }
    }
}
