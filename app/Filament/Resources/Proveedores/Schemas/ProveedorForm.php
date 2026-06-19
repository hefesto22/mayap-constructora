<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proveedores\Schemas;

use App\Enums\CondicionPago;
use App\Models\Proveedor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class ProveedorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('proveedor_tabs')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    self::tabIdentificacion(),
                    self::tabContactoYPago(),
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
                    ->helperText('Asignado automáticamente al crear. Patrón: PRV-{NÚMERO}.'),

                TextInput::make('nombre')
                    ->label('Nombre / Razón social')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('FERRETERÍA EL CONSTRUCTOR S. DE R.L.')
                    ->mayusculas()
                    ->prefixIcon('heroicon-o-building-office')
                    ->columnSpanFull(),

                TextInput::make('rtn')
                    ->label('RTN')
                    ->maxLength(14)
                    ->minLength(14)
                    ->mask('99999999999999')
                    ->placeholder('08019985012345')
                    ->rules(['nullable', 'regex:/^\d{14}$/'])
                    ->helperText('14 dígitos sin guiones. Opcional.')
                    ->unique(Proveedor::class, 'rtn', ignoreRecord: true)
                    ->prefixIcon('heroicon-o-identification'),
            ])
            ->columns(2);
    }

    private static function tabContactoYPago(): Tab
    {
        return Tab::make('Contacto y pago')
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
                    ->placeholder('ventas@proveedor.com')
                    ->prefixIcon('heroicon-o-envelope'),

                Select::make('condicion_pago')
                    ->label('Condición de pago')
                    ->options(CondicionPago::options())
                    ->default(CondicionPago::Contado->value)
                    ->required()
                    ->live()
                    ->native(false),

                TextInput::make('dias_credito')
                    ->label('Días de crédito')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->visible(fn (callable $get): bool => $get('condicion_pago') === CondicionPago::Credito->value)
                    ->helperText('Plazo acordado para pagar las compras a crédito.'),

                TextInput::make('ciudad')
                    ->label('Ciudad')
                    ->maxLength(100)
                    ->placeholder('SAN PEDRO SULA')
                    ->mayusculas()
                    ->prefixIcon('heroicon-o-map-pin'),

                Textarea::make('direccion')
                    ->label('Dirección')
                    ->rows(2)
                    ->placeholder('BARRIO EL CENTRO, AVENIDA PRINCIPAL')
                    ->mayusculas()
                    ->columnSpanFull(),

                Textarea::make('notas')
                    ->label('Notas internas')
                    ->rows(3)
                    ->placeholder('OBSERVACIONES: PRODUCTOS QUE VENDE, DESCUENTOS, CONTACTO DE VENTAS')
                    ->mayusculas()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function tabEstado(): Tab
    {
        return Tab::make('Estado')
            ->icon('heroicon-o-power')
            ->schema([
                Toggle::make('activo')
                    ->label('Proveedor activo')
                    ->default(true)
                    ->onColor('success')
                    ->offColor('danger')
                    ->helperText('Proveedores inactivos no aparecen al registrar compras.')
                    ->columnSpanFull(),

                TextInput::make('creado_at')
                    ->label('Proveedor creado')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->default(fn (?Proveedor $record): string => $record?->created_at?->format('d/m/Y H:i') ?? '—'),
            ]);
    }
}
