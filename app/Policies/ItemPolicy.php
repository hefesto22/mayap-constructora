<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Item;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Item');
    }

    public function view(AuthUser $authUser, Item $item): bool
    {
        return $authUser->can('View:Item');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Item');
    }

    public function update(AuthUser $authUser, Item $item): bool
    {
        return $authUser->can('Update:Item');
    }

    public function delete(AuthUser $authUser, Item $item): bool
    {
        return $authUser->can('Delete:Item');
    }

    public function restore(AuthUser $authUser, Item $item): bool
    {
        return $authUser->can('Restore:Item');
    }

    public function forceDelete(AuthUser $authUser, Item $item): bool
    {
        return $authUser->can('ForceDelete:Item');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Item');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Item');
    }

    public function replicate(AuthUser $authUser, Item $item): bool
    {
        return $authUser->can('Replicate:Item');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Item');
    }
}
