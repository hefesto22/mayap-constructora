<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlanMantenimiento;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Los planes de mantenimiento viven DENTRO de la ficha de la máquina
 * (relation manager), así que heredan los permisos de Maquina — cero
 * permisos Shield nuevos (misma decisión que en renta): quien puede
 * editar máquinas administra sus planes; quien solo las ve, los ve.
 */
class PlanMantenimientoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('View:Maquina');
    }

    public function view(AuthUser $authUser, PlanMantenimiento $plan): bool
    {
        return $authUser->can('View:Maquina');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Maquina');
    }

    public function update(AuthUser $authUser, PlanMantenimiento $plan): bool
    {
        return $authUser->can('Update:Maquina');
    }

    public function delete(AuthUser $authUser, PlanMantenimiento $plan): bool
    {
        return $authUser->can('Update:Maquina');
    }
}
