<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SolicitudMaquina;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SolicitudMaquinaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SolicitudMaquina');
    }

    public function view(AuthUser $authUser, SolicitudMaquina $solicitudMaquina): bool
    {
        return $authUser->can('View:SolicitudMaquina');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SolicitudMaquina');
    }

    public function update(AuthUser $authUser, SolicitudMaquina $solicitudMaquina): bool
    {
        return $authUser->can('Update:SolicitudMaquina');
    }

    public function delete(AuthUser $authUser, SolicitudMaquina $solicitudMaquina): bool
    {
        return $authUser->can('Delete:SolicitudMaquina');
    }

    public function restore(AuthUser $authUser, SolicitudMaquina $solicitudMaquina): bool
    {
        return $authUser->can('Restore:SolicitudMaquina');
    }

    public function forceDelete(AuthUser $authUser, SolicitudMaquina $solicitudMaquina): bool
    {
        return $authUser->can('ForceDelete:SolicitudMaquina');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SolicitudMaquina');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SolicitudMaquina');
    }

    public function replicate(AuthUser $authUser, SolicitudMaquina $solicitudMaquina): bool
    {
        return $authUser->can('Replicate:SolicitudMaquina');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SolicitudMaquina');
    }
}
