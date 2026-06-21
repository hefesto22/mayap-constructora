<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ParteTrabajo;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ParteTrabajoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ParteTrabajo');
    }

    public function view(AuthUser $authUser, ParteTrabajo $parteTrabajo): bool
    {
        return $authUser->can('View:ParteTrabajo');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ParteTrabajo');
    }

    public function update(AuthUser $authUser, ParteTrabajo $parteTrabajo): bool
    {
        return $authUser->can('Update:ParteTrabajo');
    }

    public function delete(AuthUser $authUser, ParteTrabajo $parteTrabajo): bool
    {
        return $authUser->can('Delete:ParteTrabajo');
    }

    public function restore(AuthUser $authUser, ParteTrabajo $parteTrabajo): bool
    {
        return $authUser->can('Restore:ParteTrabajo');
    }

    public function forceDelete(AuthUser $authUser, ParteTrabajo $parteTrabajo): bool
    {
        return $authUser->can('ForceDelete:ParteTrabajo');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ParteTrabajo');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ParteTrabajo');
    }

    public function replicate(AuthUser $authUser, ParteTrabajo $parteTrabajo): bool
    {
        return $authUser->can('Replicate:ParteTrabajo');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ParteTrabajo');
    }
}
