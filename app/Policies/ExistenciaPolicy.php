<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Existencia;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ExistenciaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Existencia');
    }

    public function view(AuthUser $authUser, Existencia $existencia): bool
    {
        return $authUser->can('View:Existencia');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Existencia');
    }

    public function update(AuthUser $authUser, Existencia $existencia): bool
    {
        return $authUser->can('Update:Existencia');
    }

    public function delete(AuthUser $authUser, Existencia $existencia): bool
    {
        return $authUser->can('Delete:Existencia');
    }

    public function restore(AuthUser $authUser, Existencia $existencia): bool
    {
        return $authUser->can('Restore:Existencia');
    }

    public function forceDelete(AuthUser $authUser, Existencia $existencia): bool
    {
        return $authUser->can('ForceDelete:Existencia');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Existencia');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Existencia');
    }

    public function replicate(AuthUser $authUser, Existencia $existencia): bool
    {
        return $authUser->can('Replicate:Existencia');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Existencia');
    }
}
