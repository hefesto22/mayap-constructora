<?php

declare(strict_types=1);

namespace App\Filament\Resources\GastosReparacion\Tables;

use App\Enums\TipoCargoObra;
use App\Models\Compra;
use App\Models\GastoMantenimiento;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class GastosMantenimientoTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'maquina:id,nombre',
                'proyecto:id,nombre',
                'mantenimiento:id,codigo',
                'compra:id,codigo',
                'bodega:id,nombre',
            ]))
            ->columns([
                TextColumn::make('fecha')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('maquina.nombre')
                    ->label('Máquina')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-truck')
                    ->description(fn (GastoMantenimiento $record): string => $record->mantenimiento->codigo),

                TextColumn::make('descripcion')
                    ->label('Qué se usó')
                    ->searchable()
                    ->limit(45)
                    ->wrap(),

                TextColumn::make('monto')
                    ->label('Monto')
                    ->money('HNL')
                    ->sortable()
                    ->alignEnd()
                    ->summarize(Sum::make()->money('HNL')->label('Total')),

                // Dónde pasó — dato, no cargo: el cargo es la columna de al lado.
                TextColumn::make('proyecto.nombre')
                    ->label('Ocurrió en')
                    ->placeholder('En el patio')
                    ->toggleable(),

                TextColumn::make('cargar_a_proyecto')
                    ->label('Carga')
                    ->badge()
                    ->formatStateUsing(fn (bool $state, GastoMantenimiento $record): string => $state
                        ? ($record->tipo_cargo_obra?->getLabel() ?? 'A la obra').' · L. '.number_format((float) $record->montoALaObra(), 2)
                        : 'Solo a la máquina')
                    ->color(fn (bool $state, GastoMantenimiento $record): string => match (true) {
                        ! $state                                          => 'gray',
                        $record->tipo_cargo_obra === TipoCargoObra::Cobro => 'success',
                        default                                           => 'warning',
                    }),

                TextColumn::make('origen')
                    ->label('De dónde salió')
                    ->badge()
                    ->description(fn (GastoMantenimiento $record): ?string => $record->origen->esDeBodega()
                        ? rtrim(rtrim((string) $record->cantidad, '0'), '.').' de '.($record->bodega->nombre ?? 'bodega')
                        : null)
                    ->toggleable(),

                TextColumn::make('compra.codigo')
                    ->label('Factura')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('conciliado_at')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Por respaldar' : 'Respaldado')
                    ->color(fn (?string $state): string => $state === null ? 'warning' : 'success'),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                TernaryFilter::make('conciliado_at')
                    ->label('Respaldo')
                    ->placeholder('Todos')
                    ->trueLabel('Respaldados')
                    ->falseLabel('Por respaldar')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('conciliado_at'),
                        false: fn ($query) => $query->whereNull('conciliado_at'),
                        blank: fn ($query) => $query,
                    ),

                TernaryFilter::make('cargar_a_proyecto')
                    ->label('Se carga a')
                    ->placeholder('Todos')
                    ->trueLabel('A la obra')
                    ->falseLabel('A la máquina'),

                SelectFilter::make('maquina_id')
                    ->label('Máquina')
                    ->relationship('maquina', 'nombre')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                // EL paso de recepción, y el único lugar donde se decide
                // quién paga (Mauricio 2026-09-05). Respaldar y decidir es
                // el mismo acto: separarlos dejaba que la decisión se
                // saltara en silencio y el gasto se quedara colgando.
                Action::make('respaldar')
                    ->label('Respaldar y decidir')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (GastoMantenimiento $record): bool => $record->estaPendiente())
                    ->modalHeading('¿Quién absorbe este gasto?')
                    ->modalDescription('Siempre queda en el historial de la máquina. Lo que se decide aquí es si además le pega a la obra.')
                    ->modalSubmitActionLabel('Respaldar')
                    ->fillForm(fn (GastoMantenimiento $record): array => [
                        'cargar_a_proyecto' => $record->cargar_a_proyecto,
                        'tipo_cargo_obra'   => $record->tipo_cargo_obra->value ?? TipoCargoObra::Gasto->value,
                        'monto_obra'        => $record->monto_obra,
                        'compra_id'         => $record->compra_id,
                    ])
                    ->schema(fn (GastoMantenimiento $record): array => [
                        Placeholder::make('resumen')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<div style="font-size:.875rem;line-height:1.5">'
                                .'<strong>'.e($record->descripcion).'</strong> · L. '.number_format((float) $record->monto, 2)
                                .'<br><span style="color:#6b7280">'.e($record->maquina->nombre)
                                .($record->proyecto !== null ? ' · ocurrió en '.e($record->proyecto->nombre) : ' · estaba en el patio')
                                .'</span></div>'
                            ))
                            ->columnSpanFull(),

                        Toggle::make('cargar_a_proyecto')
                            ->label('Cargárselo a la obra')
                            ->live()
                            ->disabled($record->proyecto_id === null)
                            ->helperText($record->proyecto_id === null
                                ? 'La máquina no estaba en ninguna obra: este gasto es de la flota y punto.'
                                : 'Apagado, el gasto se queda solo en el historial de la máquina.')
                            ->columnSpanFull(),

                        Radio::make('tipo_cargo_obra')
                            ->label('¿En qué concepto?')
                            ->options(TipoCargoObra::options())
                            ->descriptions(TipoCargoObra::descripciones())
                            ->default(TipoCargoObra::Gasto->value)
                            ->required(fn (Get $get): bool => (bool) $get('cargar_a_proyecto'))
                            ->visible(fn (Get $get): bool => (bool) $get('cargar_a_proyecto'))
                            ->columnSpanFull(),

                        TextInput::make('monto_obra')
                            ->label('Monto que se le carga a la obra')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('L.')
                            ->placeholder(number_format((float) $record->monto, 2))
                            ->visible(fn (Get $get): bool => (bool) $get('cargar_a_proyecto'))
                            ->helperText('Vacío = se le carga exactamente lo que costó.')
                            ->columnSpanFull(),

                        Select::make('compra_id')
                            ->label('Compra que la respalda')
                            ->options(fn (): array => Compra::query()
                                ->orderByDesc('fecha')
                                ->limit(200)
                                ->pluck('codigo', 'id')
                                ->all())
                            ->searchable()
                            ->placeholder('Sin factura todavía')
                            ->helperText('Opcional: se puede ligar después desde Editar.')
                            ->columnSpanFull(),
                    ])
                    ->action(function (GastoMantenimiento $record, array $data): void {
                        $carga = (bool) ($data['cargar_a_proyecto'] ?? false) && $record->proyecto_id !== null;

                        $record->forceFill([
                            'cargar_a_proyecto' => $carga,
                            'tipo_cargo_obra'   => $carga
                                ? (string) ($data['tipo_cargo_obra'] ?? TipoCargoObra::Gasto->value)
                                : null,
                            'monto_obra' => $carga && filled($data['monto_obra'] ?? null)
                                ? number_format((float) $data['monto_obra'], 2, '.', '')
                                : null,
                            'compra_id'      => $data['compra_id'] ?? null,
                            'conciliado_at'  => now(),
                            'conciliado_por' => auth()->id(),
                        ])->save();

                        Notification::make()
                            ->title('Gasto respaldado')
                            ->body("{$record->codigo} · ".($carga
                                ? ($record->tipo_cargo_obra?->getLabel() ?? 'Cargado a la obra')
                                    .' por L. '.number_format((float) $record->montoALaObra(), 2)
                                : 'Queda solo en el historial de '.$record->maquina->nombre))
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
