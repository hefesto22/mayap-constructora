<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Bodega;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class BodegaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Bodega');
    }

    public function view(AuthUser $authUser, Bodega $bodega): bool
    {
        return $authUser->can('View:Bodega');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Bodega');
    }

    public function update(AuthUser $authUser, Bodega $bodega): bool
    {
        return $authUser->can('Update:Bodega');
    }

    public function delete(AuthUser $authUser, Bodega $bodega): bool
    {
        return $authUser->can('Delete:Bodega');
    }

    public function restore(AuthUser $authUser, Bodega $bodega): bool
    {
        return $authUser->can('Restore:Bodega');
    }

    public function forceDelete(AuthUser $authUser, Bodega $bodega): bool
    {
        return $authUser->can('ForceDelete:Bodega');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Bodega');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Bodega');
    }

    public function replicate(AuthUser $authUser, Bodega $bodega): bool
    {
        return $authUser->can('Replicate:Bodega');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Bodega');
    }
}
