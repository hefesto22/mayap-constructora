<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bodegas\Tables;

use App\Models\Bodega;
use App\Support\Cantidad;
use Exception;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BodegasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-building-storefront'),
                TextColumn::make('responsable')
                    ->label('Responsable')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('existencias_count')
                    ->label('Items en stock')
                    ->counts('existencias')
                    ->badge()
                    ->color('info')
                    ->placeholder('0'),
                // Los contenedores que viajan (pipa, cisterna) se leen de
                // otra forma: no importa cuántos ítems tienen, importa qué
                // tan llenos vienen. Una bodega fija deja la celda vacía.
                TextColumn::make('nivel')
                    ->label('Carga')
                    ->badge()
                    ->state(fn (Bodega $record): ?string => self::nivelDeCarga($record))
                    ->color(fn (Bodega $record): string => match (true) {
                        ! $record->esMovil()                     => 'gray',
                        $record->estaVacio()                     => 'danger',
                        ($record->porcentajeLleno() ?? 100) < 50 => 'warning',
                        default                                  => 'success',
                    })
                    ->icon(fn (Bodega $record): ?string => $record->esMovil() ? 'heroicon-o-truck' : null)
                    ->tooltip('Cuánto carga ahora mismo. Vacío = hay que mandarlo a llenar.')
                    ->placeholder('—'),
                ToggleColumn::make('activo')
                    ->label('Activa')
                    ->onColor('success')
                    ->offColor('danger'),
                TextColumn::make('created_at')
                    ->label('Creada')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre', 'asc')
            ->filters([
                TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->before(function (Model $record): void {
                        if ($record->existencias()->exists()) {
                            throw new Exception(
                                'No se puede eliminar esta bodega: tiene existencias registradas. Márcala como inactiva en su lugar.'
                            );
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function ($records): void {
                            $records->each(function (Model $record): void {
                                if ($record->existencias()->exists()) {
                                    throw new Exception(
                                        "No se puede eliminar la bodega {$record->nombre}: tiene existencias registradas."
                                    );
                                }
                            });
                        }),
                ]),
            ]);
    }

    /**
     * "7 M3 · 70%" para un contenedor; nada para una bodega fija.
     */
    private static function nivelDeCarga(Bodega $bodega): ?string
    {
        if (! $bodega->esMovil()) {
            return null;
        }

        $bodega->loadMissing('material.unidadMedida');

        $carga = Cantidad::sinCeros($bodega->contenidoActual());
        $unidad = $bodega->material?->unidadMedida->codigo ?? '';
        $porcentaje = $bodega->porcentajeLleno();

        return trim("{$carga} {$unidad}").($porcentaje !== null ? " · {$porcentaje}%" : '');
    }
}
