<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Compra;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CompraPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Compra');
    }

    public function view(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('View:Compra');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Compra');
    }

    public function update(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('Update:Compra');
    }

    public function delete(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('Delete:Compra');
    }

    public function restore(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('Restore:Compra');
    }

    public function forceDelete(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('ForceDelete:Compra');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Compra');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Compra');
    }

    public function replicate(AuthUser $authUser, Compra $compra): bool
    {
        return $authUser->can('Replicate:Compra');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Compra');
    }
}
