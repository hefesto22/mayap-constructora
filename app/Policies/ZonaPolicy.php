<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Zona;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ZonaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Zona');
    }

    public function view(AuthUser $authUser, Zona $zona): bool
    {
        return $authUser->can('View:Zona');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Zona');
    }

    public function update(AuthUser $authUser, Zona $zona): bool
    {
        return $authUser->can('Update:Zona');
    }

    public function delete(AuthUser $authUser, Zona $zona): bool
    {
        return $authUser->can('Delete:Zona');
    }

    public function restore(AuthUser $authUser, Zona $zona): bool
    {
        return $authUser->can('Restore:Zona');
    }

    public function forceDelete(AuthUser $authUser, Zona $zona): bool
    {
        return $authUser->can('ForceDelete:Zona');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Zona');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Zona');
    }

    public function replicate(AuthUser $authUser, Zona $zona): bool
    {
        return $authUser->can('Replicate:Zona');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Zona');
    }
}
