<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shield;

use App\Filament\Resources\Shield\Pages\CreateRole;
use App\Filament\Resources\Shield\Pages\EditRole;
use App\Filament\Resources\Shield\Pages\ListRoles;
use App\Filament\Resources\Shield\Pages\ViewRole;
use App\Support\Permisos;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Str;
use Override;

/**
 * RoleResource propio que EXTIENDE el de Shield para un solo cambio: la
 * pestaña "Permisos personalizados" se agrupa por módulo con título de
 * sección (Proyectos — Ejecución, Proyectos — Visibilidad, Inventario...),
 * en vez de una lista plana donde no se sabe a qué pantalla pertenece
 * cada permiso.
 *
 * Shield detecta que el proyecto publica su propio RoleResource
 * (Utils::isResourcePublished) y NO registra el suyo — sin duplicados.
 * Los grupos salen de App\Support\Permisos::PERSONALIZADOS_POR_MODULO
 * (única fuente): un permiso nuevo aparece aquí solo, sin tocar esta clase.
 */
class RoleResource extends ShieldRoleResource
{
    /**
     * Roles vive bajo "Administración" (junto a Usuarios y Registros de
     * actividad), no en un grupo "Filament Shield" propio — el menú se
     * ordena por negocio, no por paquete.
     */
    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return 'Administración';
    }

    #[Override]
    public static function getNavigationSort(): ?int
    {
        return 30;
    }

    #[Override]
    public static function getTabFormComponentForCustomPermissions(): Component
    {
        $total = count(Permisos::PERSONALIZADOS);

        $secciones = [];

        foreach (Permisos::PERSONALIZADOS_POR_MODULO as $modulo => $permisos) {
            $secciones[] = Section::make($modulo)
                ->compact()
                ->schema([
                    static::getCheckboxListFormComponent(
                        name: 'custom_permissions_'.Str::slug($modulo, '_'),
                        options: $permisos,
                        searchable: false,
                    ),
                ]);
        }

        return Tab::make('custom_permissions')
            ->label(__('filament-shield::filament-shield.custom'))
            ->visible(fn (): bool => Utils::isCustomPermissionTabEnabled() && $total > 0)
            ->badge($total)
            ->schema($secciones);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index'  => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view'   => ViewRole::route('/{record}'),
            'edit'   => EditRole::route('/{record}/edit'),
        ];
    }
}
