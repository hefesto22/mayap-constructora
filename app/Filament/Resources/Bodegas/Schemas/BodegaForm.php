<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bodegas\Schemas;

use App\Models\Bodega;
use App\Models\Maquina;
use App\Models\Material;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class BodegaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('bodega_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('Datos de la bodega')
                        ->icon('heroicon-o-building-storefront')
                        ->schema([
                            TextInput::make('codigo')
                                ->label('Código')
                                ->disabled()
                                ->dehydrated(false)
                                ->visible(fn (string $operation): bool => $operation === 'edit')
                                ->prefixIcon('heroicon-o-hashtag')
                                ->helperText('Se genera automáticamente: BOD-00001, BOD-00002, ...'),

                            TextInput::make('nombre')
                                ->label('Nombre')
                                ->required()
                                ->maxLength(200)
                                ->mayusculas()
                                ->prefixIcon('heroicon-o-tag')
                                ->placeholder('BODEGA CENTRAL SANTA ROSA')
                                ->columnSpanFull(),

                            TextInput::make('responsable')
                                ->label('Responsable')
                                ->maxLength(150)
                                ->prefixIcon('heroicon-o-user')
                                ->placeholder('Nombre de la persona a cargo')
                                ->helperText('Persona que controla las entradas y salidas de esta bodega.'),

                            Textarea::make('direccion')
                                ->label('Dirección')
                                ->rows(2)
                                ->maxLength(500)
                                ->mayusculas()
                                ->placeholder('UBICACIÓN FÍSICA DE LA BODEGA')
                                ->columnSpanFull(),

                            // CONTENEDOR MÓVIL (Mauricio 2026-09-10): el
                            // tercer tipo de lugar que faltaba, uno que se
                            // mueve. Con esto la pipa de agua, la cisterna
                            // de diésel o la camioneta de herramienta se
                            // manejan sin código nuevo por cada caso: cada
                            // constructora declara los suyos.
                            Section::make('¿Es un contenedor que viaja?')
                                ->icon('heroicon-o-truck')
                                ->description('Una pipa de agua, una cisterna de diésel, una volqueta de reparto. Sale cargada y vuelve con lo que sobró.')
                                ->columns(2)
                                ->columnSpanFull()
                                ->schema([
                                    Toggle::make('movil')
                                        ->label('Sí, este contenedor viaja')
                                        ->live()
                                        ->onColor('warning')
                                        // Al apagarlo hay que LIMPIAR los tres
                                        // campos: el CHECK de la tabla exige que
                                        // una bodega fija no arrastre capacidad,
                                        // vehículo ni contenido.
                                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                                            if ($state === true) {
                                                return;
                                            }

                                            $set('material_id', null);
                                            $set('capacidad', null);
                                            $set('maquina_id', null);
                                        })
                                        ->helperText('Una bodega normal se queda quieta; ésta sale a la obra y vuelve.')
                                        ->columnSpanFull(),

                                    // Hay DOS clases de contenedor, y la
                                    // diferencia la dice este campo, no una
                                    // bandera aparte: si declara material carga
                                    // siempre eso (pipa, cisterna) y su regreso
                                    // se mide por nivel. Vacío = lleva lo que se
                                    // le suba (volqueta) y su regreso se mide
                                    // por lo que entregó.
                                    Select::make('material_id')
                                        ->label('¿Carga siempre lo mismo?')
                                        ->options(fn (): array => Material::query()
                                            ->where('activo', true)
                                            ->orderBy('nombre')
                                            ->pluck('nombre', 'id')
                                            ->all())
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->placeholder('No — lleva lo que se le suba')
                                        ->visible(fn (callable $get): bool => (bool) $get('movil'))
                                        // Se guarda AUNQUE esté escondido: si no,
                                        // apagar el toggle dejaría el valor viejo
                                        // en la base y reventaría el CHECK.
                                        ->dehydratedWhenHidden()
                                        ->helperText('Una pipa carga siempre agua: elegila y el regreso solo pregunta CON CUÁNTO viene. Una volqueta lleva lo que diga la requisición: dejalo vacío.'),

                                    TextInput::make('capacidad')
                                        ->label('Capacidad cuando va llena')
                                        ->numeric()
                                        ->minValue(0)
                                        ->step('any')
                                        ->visible(fn (callable $get): bool => (bool) $get('movil') && filled($get('material_id')))
                                        ->dehydratedWhenHidden()
                                        ->required(fn (callable $get): bool => (bool) $get('movil') && filled($get('material_id')))
                                        ->helperText('En la unidad del material (m³, galones…). Es lo que permite marcar "regresó a la mitad" sin teclear un número.'),

                                    Select::make('maquina_id')
                                        ->label('¿Qué vehículo lo carga?')
                                        ->options(fn (): array => Maquina::query()
                                            ->activas()
                                            ->orderBy('nombre')
                                            ->pluck('nombre', 'id')
                                            ->all())
                                        ->searchable()
                                        ->preload()
                                        ->visible(fn (callable $get): bool => (bool) $get('movil'))
                                        ->dehydratedWhenHidden()
                                        ->helperText('Ligalo al camión y el regreso se pregunta solo en el cierre del día — nadie tiene que acordarse de entrar aquí. Además, mientras reparte queda ocupado en el calendario.')
                                        ->columnSpanFull(),
                                ]),
                        ])
                        ->columns(2),

                    Tab::make('Estado')
                        ->icon('heroicon-o-power')
                        ->schema([
                            Toggle::make('activo')
                                ->label('Bodega activa')
                                ->default(true)
                                ->onColor('success')
                                ->offColor('danger')
                                ->helperText('Una bodega inactiva no aparece al despachar ni registrar entradas.')
                                ->columnSpanFull(),

                            Section::make('Información del registro')
                                ->icon('heroicon-o-information-circle')
                                ->visible(fn (string $operation): bool => $operation === 'edit')
                                ->schema([
                                    Placeholder::make('existencias_count')
                                        ->label('Items con existencia')
                                        ->content(fn (?Bodega $record): string => $record instanceof Bodega
                                            ? (string) $record->existencias()->count()
                                            : '—'),
                                    Placeholder::make('creada_at')
                                        ->label('Creada')
                                        ->content(fn (?Bodega $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
                                    Placeholder::make('cambios_registrados')
                                        ->label('Cambios registrados')
                                        ->content(function (?Bodega $record): string {
                                            if (! $record instanceof Bodega) {
                                                return '—';
                                            }

                                            $count = $record->activities()->count();

                                            return $count === 1 ? '1 cambio' : "{$count} cambios";
                                        }),
                                ])
                                ->columns(3)
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }
}
