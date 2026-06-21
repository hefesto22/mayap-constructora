<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MantenimientoMaquina;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MantenimientoMaquinaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MantenimientoMaquina');
    }

    public function view(AuthUser $authUser, MantenimientoMaquina $mantenimientoMaquina): bool
    {
        return $authUser->can('View:MantenimientoMaquina');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MantenimientoMaquina');
    }

    public function update(AuthUser $authUser, MantenimientoMaquina $mantenimientoMaquina): bool
    {
        return $authUser->can('Update:MantenimientoMaquina');
    }

    public function delete(AuthUser $authUser, MantenimientoMaquina $mantenimientoMaquina): bool
    {
        return $authUser->can('Delete:MantenimientoMaquina');
    }

    public function restore(AuthUser $authUser, MantenimientoMaquina $mantenimientoMaquina): bool
    {
        return $authUser->can('Restore:MantenimientoMaquina');
    }

    public function forceDelete(AuthUser $authUser, MantenimientoMaquina $mantenimientoMaquina): bool
    {
        return $authUser->can('ForceDelete:MantenimientoMaquina');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MantenimientoMaquina');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MantenimientoMaquina');
    }

    public function replicate(AuthUser $authUser, MantenimientoMaquina $mantenimientoMaquina): bool
    {
        return $authUser->can('Replicate:MantenimientoMaquina');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MantenimientoMaquina');
    }
}
