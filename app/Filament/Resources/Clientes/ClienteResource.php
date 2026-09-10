<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes;

use App\Filament\Resources\Clientes\Pages\CreateCliente;
use App\Filament\Resources\Clientes\Pages\EditCliente;
use App\Filament\Resources\Clientes\Pages\ListClientes;
use App\Filament\Resources\Clientes\Schemas\ClienteForm;
use App\Filament\Resources\Clientes\Tables\ClientesTable;
use App\Models\Cliente;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Override;

class ClienteResource extends Resource
{
    protected static ?string $model = Cliente::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    protected static ?int $navigationSort = 10;

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Comercial';
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return ClienteForm::configure($schema);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return ClientesTable::configure($table);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListClientes::route('/'),
            'create' => CreateCliente::route('/create'),
            'edit'   => EditCliente::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre', 'rtn', 'email'];
    }
}
