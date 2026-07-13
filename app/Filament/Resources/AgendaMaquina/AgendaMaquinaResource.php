<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgendaMaquina;

use App\Enums\EstadoProyecto;
use App\Filament\Resources\AgendaMaquina\Pages\ManageAgendaMaquina;
use App\Models\AgendaMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Agenda de máquina — compromisos FUTUROS por día y horas. Se crea desde
 * aquí o desde el botón "Agendar" del calendario (misma forma, mismo
 * service). El calendario la pinta en azul; el parte de trabajo (verde)
 * es la realidad de ese plan.
 */
class AgendaMaquinaResource extends Resource
{
    protected static ?string $model = AgendaMaquina::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'Agendado';

    protected static ?string $pluralModelLabel = 'Agenda';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return 'Maquinaria';
    }

    /**
     * Campos del "Agendar" en LOTE — varias máquinas × rango de días en un
     * solo guardado (30 máquinas diarias: mínimo de clicks). Compartidos
     * entre esta Resource, el botón del calendario y el drag sobre días
     * (única definición, cero duplicación).
     *
     * @return array<int, Component|Field>
     */
    public static function camposAgendar(): array
    {
        return [
            Select::make('maquina_ids')
                ->label('Máquinas')
                ->multiple()
                ->options(fn () => Maquina::query()->orderBy('nombre')->pluck('nombre', 'id'))
                ->searchable()
                ->preload()
                ->required()
                ->helperText('Selecciona una o varias — todas van a la misma obra.'),

            Select::make('proyecto_id')
                ->label('Obra')
                ->options(fn () => Proyecto::query()
                    ->whereIn('estado', [EstadoProyecto::EnEjecucion->value, EstadoProyecto::Pausada->value])
                    ->orderBy('nombre')
                    ->pluck('nombre', 'id'))
                ->searchable()
                ->preload()
                ->required(),

            DatePicker::make('desde')
                ->label('Desde')
                ->native(false)
                ->minDate(today())
                ->required()
                ->live(),

            DatePicker::make('hasta')
                ->label('Hasta')
                ->native(false)
                ->minDate(fn (Get $get) => $get('desde') ?? today())
                ->required()
                ->helperText('Igual a "Desde" para un solo día.'),

            TextInput::make('horas_previstas')
                ->label('Horas por día')
                ->numeric()
                ->minValue(0.5)
                ->maxValue(24)
                ->step(0.5)
                ->suffix('h')
                ->required(),

            Toggle::make('excluir_domingos')
                ->label('Excluir domingos')
                ->default(true),

            Textarea::make('notas')
                ->label('Notas')
                ->rows(2)
                ->maxLength(255),
        ];
    }

    public static function table(Table $table): Table
    {
        return Tables\AgendaMaquinaTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['maquina:id,codigo,nombre', 'proyecto:id,nombre', 'user:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAgendaMaquina::route('/'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['maquina.nombre', 'proyecto.nombre'];
    }
}
