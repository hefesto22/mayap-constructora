<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Tables;

use App\Models\Cliente;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClientesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Código copiado')
                    ->fontFamily('mono'),

                TextColumn::make('nombre')
                    ->label('Nombre / Razón social')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('rtn')
                    ->label('RTN')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->fontFamily('mono'),

                TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->placeholder('—')
                    ->copyable()
                    ->limit(30),

                TextColumn::make('ciudad')
                    ->label('Ciudad')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('proyectos_count')
                    ->label('Proyectos')
                    ->counts('proyectos')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),

                IconColumn::make('activo')
                    ->label('Activo')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos')
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (Cliente $record): bool => $record->proyectos()->doesntExist())
                    ->tooltip(fn (Cliente $record): ?string => $record->proyectos()->exists()
                        ? 'No se puede eliminar: tiene proyectos asociados'
                        : null),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('codigo', 'asc')
            ->paginated([25, 50, 100])
            ->striped();
    }
}
