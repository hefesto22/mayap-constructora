<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Tables;

use App\Enums\AlertaMantenimiento;
use App\Enums\EstadoMaquina;
use App\Enums\TipoMaquina;
use App\Filament\Resources\Maquinas\Actions\AccionEnviarAMantenimiento;
use App\Filament\Resources\Maquinas\MaquinaResource;
use App\Models\Maquina;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class MaquinasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Para pintar "Trabajando · OBRA" y la alerta de mantenimiento
            // sin N+1: el agendado de HOY confirmado y los planes viajan
            // con cada fila.
            ->modifyQueryUsing(fn ($query) => $query->with(['agendaHoyConfirmada.proyecto:id,nombre', 'planesMantenimiento']))
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
                    ->icon('heroicon-o-truck')
                    ->limit(35),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (TipoMaquina $state): string => $state->getLabel())
                    ->sortable(),
                TextColumn::make('horometro_actual')
                    ->label('Horómetro')
                    ->numeric(2)
                    ->suffix(' h')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),
                // Alerta de mantenimiento preventivo (decisión Mauricio
                // 2026-07-19): el PEOR plan activo manda — VENCIDO gana a
                // PRÓXIMO gana a Al día. Derivada al vuelo, nunca guardada.
                TextColumn::make('mantenimiento')
                    ->label('Mantenimiento')
                    ->badge()
                    ->state(function (Maquina $record): string {
                        $plan = $record->planPeorAlerta();

                        if ($plan === null) {
                            return 'Sin plan';
                        }

                        $estado = $plan->estadoAlerta();

                        return $estado === AlertaMantenimiento::AlDia
                            ? $estado->getLabel()
                            : $estado->getLabel().' · '.Str::limit($plan->nombre, 22);
                    })
                    ->color(fn (Maquina $record): string => $record->planPeorAlerta()?->estadoAlerta()->getColor() ?? 'gray')
                    ->icon(fn (Maquina $record): ?string => $record->planPeorAlerta()?->estadoAlerta()->getIcon())
                    ->tooltip(fn (Maquina $record): ?string => $record->planPeorAlerta()?->usoResumen())
                    ->toggleable(),
                TextColumn::make('tarifa_hora')
                    ->label('Tarifa/h')
                    ->money('HNL')
                    ->alignEnd()
                    ->sortable(),
                // Estado del ciclo de vida, con una capa VISUAL encima
                // (decisión Mauricio 2026-07-15): llegada confirmada HOY →
                // "Trabajando · OBRA" todo el día; mañana vuelve sola.
                // Taller y baja siempre ganan (trabajandoHoy los excluye).
                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (EstadoMaquina $state, Maquina $record): string => $record->trabajandoHoy() ? 'info' : $state->getColor())
                    ->icon(fn (EstadoMaquina $state, Maquina $record): string => $record->trabajandoHoy() ? 'heroicon-o-play-circle' : $state->getIcon())
                    ->formatStateUsing(fn (EstadoMaquina $state, Maquina $record): string => $record->trabajandoHoy()
                        ? 'Trabajando · '.Str::limit((string) $record->obraDondeTrabajaHoy(), 22)
                        : $state->getLabel())
                    ->tooltip(fn (Maquina $record): ?string => $record->trabajandoHoy()
                        ? 'Llegada confirmada hoy a las '.$record->agendaHoyConfirmada?->llegada_confirmada_at?->format('g:i A').' en '.$record->obraDondeTrabajaHoy()
                        : null)
                    ->sortable(),
                ToggleColumn::make('activo')
                    ->label('Activa')
                    ->onColor('success')
                    ->offColor('danger'),
            ])
            ->defaultSort('codigo', 'asc')
            ->filters([
                SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(TipoMaquina::options()),
                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(EstadoMaquina::options()),
                TernaryFilter::make('activo')
                    ->label('Estado de registro')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),
            ])
            ->recordActions([
                Action::make('hoja_de_vida')
                    ->label('Hoja de vida')
                    ->icon('heroicon-o-identification')
                    ->color('gray')
                    ->url(fn (Maquina $record): string => MaquinaResource::getUrl(
                        'hoja-de-vida',
                        ['record' => $record],
                    )),
                EditAction::make(),
                AccionEnviarAMantenimiento::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
