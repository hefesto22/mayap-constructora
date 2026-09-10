<?php

declare(strict_types=1);

namespace App\Filament\Resources\Operadores\Schemas;

use App\Models\Empleado;
use App\Models\Operador;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OperadorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Quién es')
                ->icon('heroicon-o-identification')
                ->schema([
                    TextInput::make('nombre')
                        ->label('Nombre completo')
                        ->required()
                        ->maxLength(150)
                        ->mayusculas()
                        ->prefixIcon('heroicon-o-user')
                        ->unique(ignoreRecord: true)
                        ->columnSpanFull(),

                    // El vínculo con planilla es OPCIONAL a propósito: el
                    // operador externo es tan real como el de planilla, y
                    // obligarlo a ser empleado dejaría fuera la mitad de la
                    // operación (decisión Mauricio 2026-09-05).
                    Select::make('empleado_id')
                        ->label('Empleado de planilla')
                        ->relationship('empleado', 'nombre')
                        ->searchable()
                        ->preload()
                        ->unique(ignoreRecord: true)
                        ->placeholder('Externo — no está en planilla')
                        ->prefixIcon('heroicon-o-users')
                        ->helperText('Déjalo vacío si es un operador externo. Ligarlo permite cruzar sus horas con la planilla.')
                        ->columnSpanFull(),

                    TextInput::make('telefono')
                        ->label('Teléfono')
                        ->tel()
                        ->maxLength(30)
                        ->prefixIcon('heroicon-o-phone')
                        ->placeholder('9999-9999'),

                    TextInput::make('licencia')
                        ->label('Licencia')
                        ->maxLength(50)
                        ->mayusculas()
                        ->prefixIcon('heroicon-o-identification')
                        ->placeholder('TIPO / NÚMERO'),
                ])
                ->columns(2),

            Section::make('Estado')
                ->icon('heroicon-o-power')
                ->schema([
                    Toggle::make('activo')
                        ->label('Operador activo')
                        ->default(true)
                        ->onColor('success')
                        ->offColor('danger')
                        ->helperText('Un operador inactivo deja de ofrecerse al registrar la jornada, pero su historial se conserva.')
                        ->columnSpanFull(),

                    Textarea::make('notas')
                        ->label('Notas')
                        ->rows(3)
                        ->maxLength(500)
                        ->mayusculas()
                        ->placeholder('QUÉ MÁQUINAS MANEJA, HORARIOS, CONTACTO DE EMERGENCIA…')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * El formulario mínimo para dar de alta un operador SIN salir del
     * modal de la jornada: en obra no se va a llenar una ficha completa
     * para anotar quién manejó hoy.
     *
     * @return array<int, mixed>
     */
    public static function altaRapida(): array
    {
        return [
            TextInput::make('nombre')
                ->label('Nombre completo')
                ->required()
                ->maxLength(150)
                ->mayusculas()
                ->prefixIcon('heroicon-o-user')
                ->unique(table: Operador::class)
                ->columnSpanFull(),

            Select::make('empleado_id')
                ->label('¿Está en planilla?')
                ->options(fn (): array => Empleado::query()
                    ->where('activo', true)
                    ->whereDoesntHave('operador')
                    ->orderBy('nombre')
                    ->pluck('nombre', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->placeholder('No — es externo')
                ->prefixIcon('heroicon-o-users')
                ->columnSpanFull(),

            TextInput::make('telefono')
                ->label('Teléfono')
                ->tel()
                ->maxLength(30)
                ->prefixIcon('heroicon-o-phone')
                ->columnSpanFull(),
        ];
    }
}
