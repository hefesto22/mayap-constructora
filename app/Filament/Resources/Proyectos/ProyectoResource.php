<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos;

use App\Enums\EstadoProyecto;
use App\Filament\Resources\Proyectos\Pages\CreateProyecto;
use App\Filament\Resources\Proyectos\Pages\EditProyecto;
use App\Filament\Resources\Proyectos\Pages\ListProyectos;
use App\Filament\Resources\Proyectos\Pages\ViewProyecto;
use App\Filament\Resources\Proyectos\RelationManagers\RenglonesRelationManager;
use App\Filament\Resources\Proyectos\Schemas\ProyectoCostoInfolist;
use App\Filament\Resources\Proyectos\Schemas\ProyectoForm;
use App\Filament\Resources\Proyectos\Tables\ProyectosTable;
use App\Models\Proyecto;
use App\Models\User;
use App\Support\Permisos;
use App\Support\Roles;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProyectoResource extends Resource
{
    /**
     * Estados operativos "vivos" — visibles para TODO rol con acceso a
     * Proyectos. El resto de estados se otorga por permiso INDIVIDUAL
     * (Permisos::VER_ESTADO_PROYECTO) desde la pestaña Personalizados
     * de la pantalla de Roles.
     *
     * @var list<EstadoProyecto>
     */
    public const array ESTADOS_VIVOS = [
        EstadoProyecto::EnEjecucion,
        EstadoProyecto::Pausada,
    ];

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
        $query = parent::getEloquentQuery()
            ->with([
                'zona:id,codigo,nombre',
                'cliente:id,codigo,nombre,rtn',
            ])
            ->withCount('renglones');

        return self::aplicarVisibilidad($query);
    }

    /**
     * Visibilidad por usuario — ÚNICA fuente (la consumen el listado y las
     * tabs de ListProyectos, así nunca se desincronizan):
     *
     *  1. Solo los estados que estadosVisibles() permite (vivas + los
     *     otorgados permiso por permiso desde la pantalla de Roles).
     *  2. Encargado de obra → además, solo SUS obras asignadas.
     *
     * Genérico: preserva el tipo del Builder que recibe — Filament tipa
     * getEloquentQuery() como Builder<Model> (no covariante con Proyecto),
     * mientras que las tabs le pasan Builder<Proyecto>.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     *
     * @return Builder<TModel>
     */
    public static function aplicarVisibilidad(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query;
        }

        $visibles = self::estadosVisibles();

        if (count($visibles) < count(EstadoProyecto::cases())) {
            $query->whereIn(
                'estado',
                array_map(fn (EstadoProyecto $e): string => $e->value, $visibles),
            );
        }

        if (Roles::soloEncargado($user)) {
            $query->whereHas('encargados', fn (Builder $q): Builder => $q->whereKey($user->id));
        }

        return $query;
    }

    /**
     * Estados de proyecto que el usuario actual puede ver: las obras VIVAS
     * siempre + cada estado comercial/histórico otorgado individualmente
     * (Permisos::VER_ESTADO_PROYECTO, pestaña Personalizados de Roles).
     *
     * @return list<EstadoProyecto>
     */
    public static function estadosVisibles(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return EstadoProyecto::cases();
        }

        $visibles = self::ESTADOS_VIVOS;

        foreach (Permisos::VER_ESTADO_PROYECTO as $estado => $permiso) {
            if ($user->can($permiso)) {
                $visibles[] = EstadoProyecto::from($estado);
            }
        }

        return $visibles;
    }

    public static function getRelations(): array
    {
        return [
            RenglonesRelationManager::class,
        ];
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
