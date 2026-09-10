<?php

declare(strict_types=1);

namespace App\Filament\Resources\GastosReparacion\Schemas;

use App\Enums\EstadoMantenimiento;
use App\Enums\OrigenGastoReparacion;
use App\Enums\TipoCargoObra;
use App\Models\Compra;
use App\Models\GastoMantenimiento;
use App\Models\MantenimientoMaquina;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class GastoMantenimientoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Qué se gastó')
                ->icon('heroicon-o-shopping-bag')
                ->schema([
                    Select::make('mantenimiento_id')
                        ->label('Reparación')
                        ->options(fn (): array => MantenimientoMaquina::query()
                            ->with('maquina:id,nombre')
                            ->where('estado', EstadoMantenimiento::EnProceso->value)
                            ->orderByDesc('fecha_inicio')
                            ->get()
                            ->mapWithKeys(fn (MantenimientoMaquina $m): array => [
                                $m->id => "{$m->codigo} · {$m->maquina->nombre}",
                            ])
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->disabledOn('edit')
                        ->prefixIcon('heroicon-o-wrench-screwdriver')
                        ->helperText('El gasto se carga al historial de la máquina de esta reparación.')
                        ->columnSpanFull(),

                    TextInput::make('descripcion')
                        ->label('¿Qué se compró?')
                        ->required()
                        ->maxLength(255)
                        ->mayusculas()
                        ->prefixIcon('heroicon-o-shopping-bag')
                        ->columnSpanFull(),

                    TextInput::make('monto')
                        ->label('Costo real')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->live(debounce: 500)
                        ->prefix('L.')
                        ->disabled(fn (?GastoMantenimiento $record): bool => $record?->origen->esDeBodega() ?? false)
                        ->dehydrated(fn (?GastoMantenimiento $record): bool => ! ($record?->origen->esDeBodega() ?? false))
                        ->helperText('Lo que le costó a la empresa. Siempre se carga al historial de la máquina.'),

                    DatePicker::make('fecha')
                        ->label('Fecha')
                        ->default(today())
                        ->required()
                        ->native(false)
                        ->displayFormat('d/m/Y'),

                    Select::make('origen')
                        ->label('¿De dónde salió?')
                        ->options(OrigenGastoReparacion::options())
                        ->default(OrigenGastoReparacion::Recepcion->value)
                        ->required()
                        ->native(false)
                        // Lo de bodega nace de un movimiento de inventario:
                        // cambiarlo acá dejaría el stock mintiendo.
                        ->disabled(fn (?GastoMantenimiento $record): bool => $record?->origen->esDeBodega() ?? false)
                        ->helperText(fn (?GastoMantenimiento $record): ?string => $record?->origen->esDeBodega() ?? false
                            ? 'Este gasto salió del inventario: su monto y su material los fijó el movimiento de bodega.'
                            : null)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            // La decisión que solo recepción toma: por defecto la avería
            // es costo de flota y NO toca el margen de la obra.
            Section::make('¿Se le carga a la obra?')
                ->icon('heroicon-o-scale')
                ->description('Por defecto una reparación es costo de la máquina, no del proyecto donde ocurrió. Actívalo solo si esta obra debe absorberlo.')
                ->schema([
                    Toggle::make('cargar_a_proyecto')
                        ->label('Cargárselo a la obra')
                        ->onColor('warning')
                        ->offColor('gray')
                        ->live()
                        ->helperText('Apagado, el gasto queda solo en el historial de la máquina.')
                        ->columnSpanFull(),

                    // No es lo mismo que la obra lo absorba a que se le
                    // cobre: uno le baja el margen, el otro es ingreso.
                    Radio::make('tipo_cargo_obra')
                        ->label('¿En qué concepto?')
                        ->options(TipoCargoObra::options())
                        ->descriptions(TipoCargoObra::descripciones())
                        ->default(TipoCargoObra::Gasto->value)
                        ->visible(fn (Get $get): bool => (bool) $get('cargar_a_proyecto'))
                        ->required(fn (Get $get): bool => (bool) $get('cargar_a_proyecto'))
                        ->columnSpanFull(),

                    TextInput::make('monto_obra')
                        ->label('Monto que se le carga a la obra')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('L.')
                        ->visible(fn (Get $get): bool => (bool) $get('cargar_a_proyecto'))
                        ->placeholder(fn (Get $get): string => is_numeric($get('monto'))
                            ? number_format((float) $get('monto'), 2)
                            : 'El costo tal cual')
                        ->helperText('Déjalo vacío para cargarle exactamente lo que costó. El costo de la máquina no cambia: cobrar más no abarata la reparación.')
                        ->columnSpanFull(),

                    Select::make('compra_id')
                        ->label('Compra que la respalda')
                        ->options(fn (): array => Compra::query()
                            ->orderByDesc('fecha')
                            ->limit(200)
                            ->pluck('codigo', 'id')
                            ->all())
                        ->searchable()
                        ->placeholder('Sin factura ligada todavía')
                        ->prefixIcon('heroicon-o-document-text')
                        ->helperText('Al ligarla, el gasto queda respaldado y sale de la bandeja de pendientes.')
                        ->columnSpanFull(),

                    Textarea::make('notas')
                        ->label('Notas')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
