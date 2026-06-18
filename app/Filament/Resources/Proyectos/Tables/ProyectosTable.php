<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Tables;

use App\Enums\EstadoProyecto;
use App\Models\Proyecto;
use App\Models\Zona;
use App\Services\Proyectos\CalcularPrecioProyectoService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProyectosTable
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
                    ->fontFamily('mono'),

                TextColumn::make('zona.codigo')
                    ->label('Zona')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(40),

                TextColumn::make('nombre')
                    ->label('Proyecto')
                    ->searchable()
                    ->wrap()
                    ->limit(40),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoProyecto $state): string => $state->getColor())
                    ->icon(fn (EstadoProyecto $state): string => $state->getIcon())
                    ->formatStateUsing(fn (EstadoProyecto $state): string => $state->getLabel())
                    ->sortable(),

                TextColumn::make('fecha_emision')
                    ->label('Emitido')
                    ->date('d/M/Y')
                    ->sortable(),

                TextColumn::make('fecha_validez')
                    ->label('Válido hasta')
                    ->date('d/M/Y')
                    ->sortable()
                    ->color(fn (Proyecto $record): string => $record->fecha_validez->isPast() ? 'danger' : 'gray'),

                TextColumn::make('renglones_count')
                    ->label('Renglones')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),

                TextColumn::make('total_cache')
                    ->label('Total')
                    ->money('HNL')
                    ->sortable()
                    ->weight('bold')
                    ->color('emerald')
                    ->summarize(Sum::make()->money('HNL')->label('Total página')),
            ])
            ->filters([
                SelectFilter::make('zona_id')
                    ->label('Zona')
                    ->options(fn (): array => Zona::activas()->pluck('codigo', 'id')->all())
                    ->preload(),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoProyecto::options()),

                SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nombre')
                    ->searchable()
                    ->preload(),

                Filter::make('fecha_emision')
                    ->schema([
                        DatePicker::make('emitido_desde')->native(false),
                        DatePicker::make('emitido_hasta')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['emitido_desde'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('fecha_emision', '>=', $d))
                            ->when($data['emitido_hasta'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('fecha_emision', '<=', $d));
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('recalcular')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->action(function (Proyecto $record): void {
                        app(CalcularPrecioProyectoService::class)->recalcular($record);

                        Notification::make()
                            ->title('Totales recalculados')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Proyecto $record): bool => $record->renglones()->exists()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('codigo', 'desc')
            ->paginated([25, 50, 100])
            ->striped()
            ->poll('60s');
    }
}
