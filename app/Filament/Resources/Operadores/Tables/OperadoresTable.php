<?php

declare(strict_types=1);

namespace App\Filament\Resources\Operadores\Tables;

use App\Models\Operador;
use Exception;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class OperadoresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('empleado:id,nombre,cargo')->withCount('partes'))
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('nombre')
                    ->label('Operador')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-user')
                    ->description(fn (Operador $record): ?string => $record->empleado?->cargo),

                // Lo primero que se quiere saber de un operador: si su pago
                // sale de planilla o de una factura de servicio.
                TextColumn::make('empleado.nombre')
                    ->label('Planilla')
                    ->badge()
                    ->color(fn (Operador $record): string => $record->esExterno() ? 'gray' : 'success')
                    ->formatStateUsing(fn (?string $state): string => $state ?? 'Externo')
                    ->default('Externo')
                    ->sortable(),

                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->icon('heroicon-o-phone')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('licencia')
                    ->label('Licencia')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('maquinas_count')
                    ->label('Máquinas a cargo')
                    ->counts('maquinas')
                    ->badge()
                    ->color('info')
                    ->toggleable(),

                TextColumn::make('partes_count')
                    ->label('Jornadas')
                    ->badge()
                    ->color('warning')
                    ->toggleable(),

                ToggleColumn::make('activo')
                    ->label('Activo')
                    ->onColor('success')
                    ->offColor('danger'),
            ])
            ->defaultSort('nombre', 'asc')
            ->filters([
                TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),

                TernaryFilter::make('empleado_id')
                    ->label('Procedencia')
                    ->placeholder('Todos')
                    ->trueLabel('De planilla')
                    ->falseLabel('Externos')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('empleado_id'),
                        false: fn ($query) => $query->whereNull('empleado_id'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->before(function (Operador $record): void {
                        if ($record->partes()->exists() || $record->consumos()->exists()) {
                            throw new Exception(
                                'No se puede eliminar este operador: ya tiene jornadas registradas. Márcalo como inactivo en su lugar.'
                            );
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function ($records): void {
                            $records->each(function (Operador $record): void {
                                if ($record->partes()->exists() || $record->consumos()->exists()) {
                                    throw new Exception(
                                        "No se puede eliminar a {$record->nombre}: ya tiene jornadas registradas."
                                    );
                                }
                            });
                        }),
                ]),
            ]);
    }
}
