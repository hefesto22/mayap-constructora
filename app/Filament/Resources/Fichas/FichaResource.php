<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fichas;

use App\Filament\Resources\Fichas\Pages\CreateFicha;
use App\Filament\Resources\Fichas\Pages\EditFicha;
use App\Filament\Resources\Fichas\Pages\ListFichas;
use App\Filament\Resources\Fichas\Pages\ViewFicha;
use App\Filament\Resources\Fichas\Schemas\FichaForm;
use App\Filament\Resources\Fichas\Tables\FichasTable;
use App\Models\Ficha;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FichaResource extends Resource
{
    protected static ?string $model = Ficha::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Ficha APU';

    protected static ?string $pluralModelLabel = 'Fichas APU';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return 'Fichas APU';
    }

    public static function getNavigationLabel(): string
    {
        return 'Fichas APU';
    }

    public static function getBreadcrumb(): string
    {
        return 'Fichas APU';
    }

    public static function form(Schema $schema): Schema
    {
        return FichaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FichasTable::configure($table);
    }

    /**
     * Eager loading global del Resource — evita N+1 en listado y form.
     *
     * `lineas.item.unidadMedida` se carga porque el form de edición
     * y el resumen de cálculo lo necesitan. Filament solo lo carga
     * cuando realmente se accede a la relación, así que en el listado
     * (que no toca lineas) no se penaliza.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'zona:id,codigo,nombre',
                'unidadMedida:id,codigo,simbolo,nombre',
            ])
            ->withCount('lineas');
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListFichas::route('/'),
            'create' => CreateFicha::route('/create'),
            'edit'   => EditFicha::route('/{record}/edit'),
            'view'   => ViewFicha::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'nombre'];
    }
}
