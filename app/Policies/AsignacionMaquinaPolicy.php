<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AsignacionMaquina;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AsignacionMaquinaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AsignacionMaquina');
    }

    public function view(AuthUser $authUser, AsignacionMaquina $asignacionMaquina): bool
    {
        return $authUser->can('View:AsignacionMaquina');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AsignacionMaquina');
    }

    public function update(AuthUser $authUser, AsignacionMaquina $asignacionMaquina): bool
    {
        return $authUser->can('Update:AsignacionMaquina');
    }

    public function delete(AuthUser $authUser, AsignacionMaquina $asignacionMaquina): bool
    {
        return $authUser->can('Delete:AsignacionMaquina');
    }

    public function restore(AuthUser $authUser, AsignacionMaquina $asignacionMaquina): bool
    {
        return $authUser->can('Restore:AsignacionMaquina');
    }

    public function forceDelete(AuthUser $authUser, AsignacionMaquina $asignacionMaquina): bool
    {
        return $authUser->can('ForceDelete:AsignacionMaquina');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AsignacionMaquina');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AsignacionMaquina');
    }

    public function replicate(AuthUser $authUser, AsignacionMaquina $asignacionMaquina): bool
    {
        return $authUser->can('Replicate:AsignacionMaquina');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AsignacionMaquina');
    }
}
