<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BitacoraMantenimiento;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * La bitácora hereda los permisos del mantenimiento (cero permisos
 * Shield nuevos — misma decisión que planes de mantenimiento y
 * reportes fiscales): quien ve mantenimientos ve su historial. Las
 * entradas solo nacen por las acciones del mantenimiento; jamás se
 * editan ni se borran desde el panel.
 */
class BitacoraMantenimientoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MantenimientoMaquina');
    }

    public function view(AuthUser $authUser, BitacoraMantenimiento $bitacora): bool
    {
        return $authUser->can('View:MantenimientoMaquina');
    }

    public function create(AuthUser $authUser): bool
    {
        return false; // Solo por las acciones del mantenimiento (servicio).
    }

    public function update(AuthUser $authUser, BitacoraMantenimiento $bitacora): bool
    {
        return false; // La bitácora es historial: no se edita.
    }

    public function delete(AuthUser $authUser, BitacoraMantenimiento $bitacora): bool
    {
        return false; // Ni se borra.
    }
}
