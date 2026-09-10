<?php

declare(strict_types=1);

namespace App\Filament\Resources\Operadores;

use App\Filament\Resources\Operadores\Pages\CreateOperador;
use App\Filament\Resources\Operadores\Pages\EditOperador;
use App\Filament\Resources\Operadores\Pages\ListOperadores;
use App\Filament\Resources\Operadores\Schemas\OperadorForm;
use App\Filament\Resources\Operadores\Tables\OperadoresTable;
use App\Models\Operador;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

class OperadorResource extends Resource
{
    protected static ?string $model = Operador::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Operador';

    protected static ?string $pluralModelLabel = 'Operadores';

    protected static ?int $navigationSort = 15;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Maquinaria';
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return 'Operadores';
    }

    #[Override]
    public static function getBreadcrumb(): string
    {
        return 'Operadores';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return OperadorForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return OperadoresTable::configure($table);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListOperadores::route('/'),
            'create' => CreateOperador::route('/create'),
            'edit'   => EditOperador::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre'];
    }
}
