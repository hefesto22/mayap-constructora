<?php

declare(strict_types=1);

namespace App\Filament\Resources\Requisiciones;

use App\Filament\Resources\Requisiciones\Pages\CreateRequisicion;
use App\Filament\Resources\Requisiciones\Pages\EditRequisicion;
use App\Filament\Resources\Requisiciones\Pages\ListRequisiciones;
use App\Filament\Resources\Requisiciones\Pages\ViewRequisicion;
use App\Filament\Resources\Requisiciones\RelationManagers\TransicionesRelationManager;
use App\Filament\Resources\Requisiciones\Schemas\RequisicionForm;
use App\Filament\Resources\Requisiciones\Tables\RequisicionesTable;
use App\Models\Requisicion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RequisicionResource extends Resource
{
    protected static ?string $model = Requisicion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'codigo';

    protected static ?string $modelLabel = 'Requisición';

    protected static ?string $pluralModelLabel = 'Requisiciones';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    public static function form(Schema $schema): Schema
    {
        return RequisicionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RequisicionesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['proyecto:id,codigo,nombre', 'solicitante:id,name']);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            TransicionesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListRequisiciones::route('/'),
            'create' => CreateRequisicion::route('/create'),
            'view'   => ViewRequisicion::route('/{record}'),
            'edit'   => EditRequisicion::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo'];
    }
}
