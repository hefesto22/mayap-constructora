<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Material;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MaterialPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Material');
    }

    public function view(AuthUser $authUser, Material $material): bool
    {
        return $authUser->can('View:Material');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Material');
    }

    public function update(AuthUser $authUser, Material $material): bool
    {
        return $authUser->can('Update:Material');
    }

    public function delete(AuthUser $authUser, Material $material): bool
    {
        return $authUser->can('Delete:Material');
    }

    public function restore(AuthUser $authUser, Material $material): bool
    {
        return $authUser->can('Restore:Material');
    }

    public function forceDelete(AuthUser $authUser, Material $material): bool
    {
        return $authUser->can('ForceDelete:Material');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Material');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Material');
    }

    public function replicate(AuthUser $authUser, Material $material): bool
    {
        return $authUser->can('Replicate:Material');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Material');
    }
}
