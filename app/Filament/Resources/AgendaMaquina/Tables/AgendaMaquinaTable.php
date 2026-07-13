<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgendaMaquina\Tables;

use App\Models\AgendaMaquina;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabla de la agenda: qué máquina va a dónde, cuándo y por cuántas horas.
 *
 * Editar solo permite ajustar horas/notas — cambiar máquina, obra o fecha
 * es OTRO compromiso: se borra este y se agenda de nuevo (así las
 * validaciones de choque del service siempre aplican).
 */
final class AgendaMaquinaTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/M/Y')
                    ->sortable()
                    ->badge()
                    ->color(fn (AgendaMaquina $record): string => $record->fecha->isToday()
                        ? 'warning'
                        : ($record->fecha->isPast() ? 'gray' : 'info')),

                TextColumn::make('maquina.nombre')
                    ->label('Máquina')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('proyecto.nombre')
                    ->label('Obra')
                    ->searchable()
                    ->limit(35),

                TextColumn::make('horas_previstas')
                    ->label('Horas')
                    ->numeric(2)
                    ->suffix(' h')
                    ->alignRight(),

                TextColumn::make('notas')
                    ->label('Notas')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('user.name')
                    ->label('Agendó')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('maquina_id')
                    ->label('Máquina')
                    ->relationship('maquina', 'nombre')
                    ->searchable()
                    ->preload(),

                Filter::make('pendientes')
                    ->label('Solo pendientes (hoy en adelante)')
                    ->query(fn (Builder $query): Builder => $query->whereDate('fecha', '>=', today()))
                    ->default(),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        TextInput::make('horas_previstas')
                            ->label('Horas previstas')
                            ->numeric()
                            ->minValue(0.5)
                            ->maxValue(24)
                            ->step(0.5)
                            ->suffix('h')
                            ->required(),
                        Textarea::make('notas')
                            ->label('Notas')
                            ->rows(2)
                            ->maxLength(255),
                    ]),
                DeleteAction::make()
                    ->label('Cancelar')
                    ->modalHeading('Cancelar agendado')
                    ->modalDescription('El compromiso se elimina del calendario. Esta acción no se puede deshacer.'),
            ])
            ->defaultSort('fecha', 'asc')
            ->emptyStateHeading('Sin compromisos agendados')
            ->emptyStateDescription('Usa "Agendar" aquí o desde el calendario para comprometer una máquina a una obra en una fecha.')
            ->paginated([25, 50]);
    }
}
