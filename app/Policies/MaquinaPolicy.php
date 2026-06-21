<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Maquina;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MaquinaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Maquina');
    }

    public function view(AuthUser $authUser, Maquina $maquina): bool
    {
        return $authUser->can('View:Maquina');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Maquina');
    }

    public function update(AuthUser $authUser, Maquina $maquina): bool
    {
        return $authUser->can('Update:Maquina');
    }

    public function delete(AuthUser $authUser, Maquina $maquina): bool
    {
        return $authUser->can('Delete:Maquina');
    }

    public function restore(AuthUser $authUser, Maquina $maquina): bool
    {
        return $authUser->can('Restore:Maquina');
    }

    public function forceDelete(AuthUser $authUser, Maquina $maquina): bool
    {
        return $authUser->can('ForceDelete:Maquina');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Maquina');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Maquina');
    }

    public function replicate(AuthUser $authUser, Maquina $maquina): bool
    {
        return $authUser->can('Replicate:Maquina');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Maquina');
    }
}
