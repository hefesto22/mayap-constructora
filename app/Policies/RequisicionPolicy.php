<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Requisicion;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RequisicionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Requisicion');
    }

    public function view(AuthUser $authUser, Requisicion $requisicion): bool
    {
        return $authUser->can('View:Requisicion');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Requisicion');
    }

    public function update(AuthUser $authUser, Requisicion $requisicion): bool
    {
        return $authUser->can('Update:Requisicion');
    }

    public function delete(AuthUser $authUser, Requisicion $requisicion): bool
    {
        return $authUser->can('Delete:Requisicion');
    }

    public function restore(AuthUser $authUser, Requisicion $requisicion): bool
    {
        return $authUser->can('Restore:Requisicion');
    }

    public function forceDelete(AuthUser $authUser, Requisicion $requisicion): bool
    {
        return $authUser->can('ForceDelete:Requisicion');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Requisicion');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Requisicion');
    }

    public function replicate(AuthUser $authUser, Requisicion $requisicion): bool
    {
        return $authUser->can('Replicate:Requisicion');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Requisicion');
    }
}
