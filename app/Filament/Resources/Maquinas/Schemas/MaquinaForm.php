<?php

declare(strict_types=1);

namespace App\Filament\Resources\Maquinas\Schemas;

use App\Enums\ModalidadTrabajo;
use App\Enums\TipoMaquina;
use App\Filament\Resources\Operadores\Schemas\OperadorForm;
use App\Models\Maquina;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class MaquinaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('maquina_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::tabIdentificacion(),
                    self::tabOperacion(),
                    self::tabEstado(),
                ]),
        ]);
    }

    private static function tabIdentificacion(): Tab
    {
        return Tab::make('Identificación')
            ->icon('heroicon-o-identification')
            ->schema([
                TextInput::make('codigo')
                    ->label('Código del sistema')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->prefixIcon('heroicon-o-hashtag')
                    ->helperText('Asignado automáticamente al crear. Patrón: MAQ-{NÚMERO}.'),

                TextInput::make('nombre')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('EXCAVADORA CAT 320')
                    ->mayusculas()
                    ->prefixIcon('heroicon-o-truck')
                    ->columnSpanFull(),

                Select::make('tipo')
                    ->label('Tipo de máquina')
                    ->options(TipoMaquina::options())
                    ->default(TipoMaquina::Otro->value)
                    ->required()
                    ->native(false),

                TextInput::make('serie')
                    ->label('N.º de serie / placa')
                    ->maxLength(100)
                    ->placeholder('SN-12345')
                    ->mayusculas()
                    ->prefixIcon('heroicon-o-hashtag'),

                TextInput::make('marca')
                    ->label('Marca')
                    ->maxLength(100)
                    ->placeholder('CATERPILLAR')
                    ->mayusculas(),

                TextInput::make('modelo')
                    ->label('Modelo')
                    ->maxLength(100)
                    ->placeholder('320D')
                    ->mayusculas(),

                TextInput::make('anio')
                    ->label('Año')
                    ->numeric()
                    ->minValue(1950)
                    ->maxValue((int) date('Y') + 1)
                    ->placeholder('2020'),
            ])
            ->columns(2);
    }

    private static function tabOperacion(): Tab
    {
        return Tab::make('Operación y tarifa')
            ->icon('heroicon-o-clock')
            ->schema([
                TextInput::make('horometro_actual')
                    ->label('Horómetro actual')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->default(0)
                    ->suffix('h')
                    ->helperText('Lectura inicial del reloj de horas. Luego lo mueven los partes de trabajo.'),

                TextInput::make('kilometraje_actual')
                    ->label('Kilometraje actual')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->suffix('km')
                    ->helperText('Para unidades por km (pick-ups, volquetas). Lo suman los partes por kilometraje; también se ajusta aquí o al registrar mantenimiento.'),

                // Cómo funciona esta unidad (Mauricio 2026-07-20): pesada por
                // horómetro, pick-ups por km, volquetas por viajes, camiones
                // por flete. Desde 2026-09-04 NO es solo el default del parte:
                // determina POR DÓNDE SE COBRA. Antes todo se costeaba por
                // horas y el margen de las obras con volquetas salía mal.
                Select::make('modalidad_trabajo')
                    ->label('Cómo trabaja esta máquina')
                    ->options(ModalidadTrabajo::options())
                    ->default(ModalidadTrabajo::Horas->value)
                    ->required()
                    ->live()
                    ->native(false)
                    ->helperText('Define cómo se le cobra el trabajo a la obra: por hora, por kilómetro, por viaje o por flete.'),

                // Quién la maneja normalmente (Mauricio 2026-09-05): casi
                // siempre es el mismo señor en la misma máquina, así que el
                // parte del día lo trae puesto y solo se cambia el día que
                // maneja otro. De planilla o externo — eso lo dice su ficha.
                Select::make('operador_habitual_id')
                    ->label('Operador habitual')
                    ->relationship('operadorHabitual', 'nombre', fn ($query) => $query->where('activo', true)->orderBy('nombre'))
                    ->searchable()
                    ->preload()
                    ->placeholder('Sin operador fijo')
                    ->prefixIcon('heroicon-o-identification')
                    ->createOptionForm(OperadorForm::altaRapida())
                    ->createOptionModalHeading('Nuevo operador')
                    ->helperText('Aparece ya seleccionado al registrar la jornada de esta máquina. Si no está en la lista, se da de alta desde aquí mismo.'),

                // Rendimiento nominal: prellena el consumo estimado del día
                // (horas de motor × L/h) y sirve de referencia para comparar
                // contra el combustible REALMENTE cargado. Un rendimiento real
                // muy por encima del nominal es fuga, falla mecánica o robo.
                // Opcional a propósito: remolques, plataformas y contenedores
                // no tienen motor y nunca van a consumir.
                TextInput::make('litros_por_hora')
                    ->label('Consumo promedio')
                    ->numeric()
                    ->minValue(0)
                    ->step('any')
                    ->suffix('L/h')
                    ->helperText('Litros de combustible por hora de motor. Estima el consumo del día y delata desvíos contra lo que realmente se tanquea. Vacío en unidades sin motor (remolques, contenedores).'),

                // ── Rate card ────────────────────────────────────────────
                // Cada presentación lleva SU precio: no se derivan una de
                // otra. En el mercado de renta el día no es 8 × la hora ni la
                // semana 6 × el día — hay descuento por volumen (el
                // benchmark pone la hora en ~15% del día y el día en ~25% de
                // la semana). Derivarlas sobrecotizaba ~20%.
                Section::make('Tarifas')
                    ->description('Lo que cobra esta máquina. Las presentaciones de renta (hora, día, semana, mes) se pactan aparte porque el mercado da descuento por volumen: un día no vale 8 veces la hora.')
                    ->icon('heroicon-o-banknotes')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextInput::make('tarifa_hora')
                            ->label('Por hora')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->default(0)
                            ->prefix('L.')
                            ->helperText('También es el costo por hora que se carga a la obra propia.'),

                        TextInput::make('tarifa_dia')
                            ->label('Por día')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('L.'),

                        TextInput::make('tarifa_semana')
                            ->label('Por semana')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('L.'),

                        TextInput::make('tarifa_mes')
                            ->label('Por mes')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('L.'),

                        TextInput::make('tarifa_viaje')
                            ->label('Por viaje')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('L.')
                            ->helperText('Volquetas y camiones que trabajan a destajo.'),

                        TextInput::make('tarifa_km')
                            ->label('Por kilómetro')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('L.')
                            ->helperText('Pick-ups y vehículos livianos.'),

                        TextInput::make('tarifa_flete')
                            ->label('Por flete')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->prefix('L.')
                            ->helperText('Monto fijo del acarreo completo.'),

                        // Ya NO es "la jornada de la máquina": esa no existe,
                        // hay días de 4 horas y días de 12 (Mauricio
                        // 2026-09-04). Es el TOPE incluido en el día que se le
                        // vende al cliente, y de ahí salen las horas de la
                        // semana (× 6 días) y del mes (× 24).
                        TextInput::make('horas_dia_renta')
                            ->label('Horas incluidas en el día')
                            ->numeric()
                            ->minValue(0.5)
                            ->step('any')
                            ->default(8)
                            ->suffix('h')
                            ->required()
                            ->helperText('Cuántas horas incluye un día de renta. Lo que se pase de ahí se cobra como excedente al cerrar. La semana son 6 de estos días y el mes, 24.'),
                    ]),

            ])
            ->columns(2);
    }

    private static function tabEstado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-power')
            ->schema([
                Toggle::make('activo')
                    ->label('Máquina activa')
                    ->default(true)
                    ->onColor('success')
                    ->offColor('danger')
                    ->helperText('Las máquinas inactivas no aparecen al asignar a obras.')
                    ->columnSpanFull(),

                Textarea::make('notas')
                    ->label('Notas internas')
                    ->rows(3)
                    ->placeholder('OBSERVACIONES: ACCESORIOS, MANTENIMIENTOS PENDIENTES, OPERADOR HABITUAL')
                    ->mayusculas()
                    ->columnSpanFull(),

                TextInput::make('creado_at')
                    ->label('Máquina creada')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->default(fn (?Maquina $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
            ]);
    }
}
