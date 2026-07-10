<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shield;

use App\Support\Permisos;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Str;

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

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'view'   => Pages\ViewRole::route('/{record}'),
            'edit'   => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
