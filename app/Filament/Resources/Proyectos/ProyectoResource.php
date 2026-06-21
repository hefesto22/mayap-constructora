<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos;

use App\Filament\Resources\Proyectos\Pages\CreateProyecto;
use App\Filament\Resources\Proyectos\Pages\EditProyecto;
use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Filament\Resources\Proyectos\Pages\ViewProyecto;
use App\Filament\Resources\Proyectos\Schemas\ProyectoCostoInfolist;
use App\Filament\Resources\Proyectos\Schemas\ProyectoForm;
use App\Filament\Resources\Proyectos\Tables\ProyectosTable;
use App\Models\Proyecto;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProyectoResource extends Resource
{
    protected static ?string $model = Proyecto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'codigo';

    protected static ?string $modelLabel = 'Proyecto';

    protected static ?string $pluralModelLabel = 'Proyectos';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'Comercial';
    }

    public static function form(Schema $schema): Schema
    {
        return ProyectoForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProyectoCostoInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProyectosTable::configure($table);
    }

    /**
     * Eager loading global del Resource — evita N+1 en el listado y edit.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'zona:id,codigo,nombre',
                'cliente:id,codigo,nombre,rtn',
            ])
            ->withCount('renglones');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProyectos::route('/'),
            'create' => CreateProyecto::route('/create'),
            'view'   => ViewProyecto::route('/{record}'),
            'edit'   => EditProyecto::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre'];
    }
}
