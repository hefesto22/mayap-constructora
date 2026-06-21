<?php

declare(strict_types=1);

namespace App\Filament\Resources\Empleados;

use App\Filament\Resources\Empleados\Pages\CreateEmpleado;
use App\Filament\Resources\Empleados\Pages\EditEmpleado;
use App\Filament\Resources\Empleados\Pages\ListEmpleados;
use App\Filament\Resources\Empleados\Schemas\EmpleadoForm;
use App\Filament\Resources\Empleados\Tables\EmpleadosTable;
use App\Models\Empleado;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EmpleadoResource extends Resource
{
    protected static ?string $model = Empleado::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Empleado';

    protected static ?string $pluralModelLabel = 'Empleados';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'Planilla';
    }

    public static function form(Schema $schema): Schema
    {
        return EmpleadoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmpleadosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListEmpleados::route('/'),
            'create' => CreateEmpleado::route('/create'),
            'edit'   => EditEmpleado::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre', 'identidad', 'cargo'];
    }
}
