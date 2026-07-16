<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgendaMaquina\Tables;

use App\Filament\Resources\AgendaMaquina\AgendaMaquinaResource;
use App\Models\AgendaMaquina;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Tabla de la agenda: qué máquina va a dónde, cuándo y a qué hora llega.
 *
 * Editar solo permite ajustar la hora de llegada y las notas — cambiar
 * máquina, obra o fecha es OTRO compromiso: se borra este y se agenda de
 * nuevo (así las validaciones del service siempre aplican).
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

                TextColumn::make('hora_entrada')
                    ->label('Llegada')
                    // AM/PM: el formato que se maneja en la constructora.
                    ->formatStateUsing(fn (?string $state): string => $state !== null
                        ? Carbon::parse($state)->format('g:i A')
                        : '—')
                    ->placeholder('—'),

                TextColumn::make('maquina.nombre')
                    ->label('Máquina')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('proyecto.nombre')
                    ->label('Obra')
                    ->searchable()
                    ->limit(35),

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
                        Select::make('hora_entrada')
                            ->label('Hora de llegada')
                            ->options(AgendaMaquinaResource::opcionesHoraLlegada())
                            ->searchable()
                            ->required()
                            // La DB guarda TIME con segundos ('08:00:00');
                            // las opciones van 'H:i' — normalizar al hidratar.
                            ->afterStateHydrated(function (Select $component, ?string $state): void {
                                if ($state !== null) {
                                    $component->state(substr($state, 0, 5));
                                }
                            }),
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
