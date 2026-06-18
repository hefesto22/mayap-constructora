<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Schemas;

use App\Models\Cliente;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ClienteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('cliente_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::tabIdentificacion(),
                    self::tabContacto(),
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
                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                    ->helperText('Asignado automáticamente al crear. Patrón: CLI-{NÚMERO}.'),

                TextInput::make('nombre')
                    ->label('Nombre / Razón social')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('COMERCIAL HONDUREÑA S.A. DE C.V.')
                    ->mayusculas()
                    ->prefixIcon('heroicon-o-user')
                    ->columnSpanFull(),

                TextInput::make('rtn')
                    ->label('RTN')
                    ->maxLength(14)
                    ->minLength(14)
                    ->mask('99999999999999')
                    ->placeholder('08019985012345')
                    ->rules(['nullable', 'regex:/^\d{14}$/'])
                    ->helperText('14 dígitos sin guiones. Opcional para personas individuales.')
                    ->unique(Cliente::class, 'rtn', ignoreRecord: true)
                    ->prefixIcon('heroicon-o-identification'),
            ])
            ->columns(2);
    }

    private static function tabContacto(): Tab
    {
        return Tab::make('Contacto')
            ->icon('heroicon-o-phone')
            ->schema([
                TextInput::make('telefono')
                    ->label('Teléfono')
                    ->tel()
                    ->maxLength(30)
                    ->placeholder('2552-3300 / 9988-7766')
                    ->prefixIcon('heroicon-o-phone'),

                TextInput::make('email')
                    ->label('Correo electrónico')
                    ->email()
                    ->maxLength(150)
                    ->placeholder('contacto@cliente.com')
                    ->prefixIcon('heroicon-o-envelope'),

                TextInput::make('ciudad')
                    ->label('Ciudad')
                    ->maxLength(100)
                    ->placeholder('SANTA ROSA DE COPÁN')
                    ->mayusculas()
                    ->prefixIcon('heroicon-o-map-pin'),

                Textarea::make('direccion')
                    ->label('Dirección')
                    ->rows(2)
                    ->placeholder('BARRIO EL CENTRO, FRENTE AL PARQUE CENTRAL')
                    ->mayusculas()
                    ->columnSpanFull(),

                Textarea::make('notas')
                    ->label('Notas internas')
                    ->rows(3)
                    ->placeholder('OBSERVACIONES SOBRE EL CLIENTE: TÉRMINOS DE PAGO, PROYECTOS PASADOS, ETC.')
                    ->mayusculas()
                    ->columnSpanFull()
                    ->helperText('Solo visible para el equipo interno. NO aparece en cotizaciones.'),
            ])
            ->columns(2);
    }

    private static function tabEstado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-power')
            ->schema([
                Toggle::make('activo')
                    ->label('Cliente activo')
                    ->default(true)
                    ->onColor('success')
                    ->offColor('danger')
                    ->helperText('Clientes inactivos no aparecen al crear nuevos proyectos.')
                    ->columnSpanFull(),

                Section::make('Resumen')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        TextInput::make('proyectos_count_label')
                            ->label('Cantidad de proyectos')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(fn (?Cliente $record): string => $record !== null
                                ? (string) $record->proyectos()->count()
                                : '—'),

                        TextInput::make('creado_at')
                            ->label('Cliente creado')
                            ->disabled()
                            ->dehydrated(false)
                            ->default(fn (?Cliente $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
