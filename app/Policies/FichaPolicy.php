<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ficha;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FichaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Ficha');
    }

    public function view(AuthUser $authUser, Ficha $ficha): bool
    {
        return $authUser->can('View:Ficha');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Ficha');
    }

    public function update(AuthUser $authUser, Ficha $ficha): bool
    {
        return $authUser->can('Update:Ficha');
    }

    public function delete(AuthUser $authUser, Ficha $ficha): bool
    {
        return $authUser->can('Delete:Ficha');
    }

    public function restore(AuthUser $authUser, Ficha $ficha): bool
    {
        return $authUser->can('Restore:Ficha');
    }

    public function forceDelete(AuthUser $authUser, Ficha $ficha): bool
    {
        return $authUser->can('ForceDelete:Ficha');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Ficha');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Ficha');
    }

    public function replicate(AuthUser $authUser, Ficha $ficha): bool
    {
        return $authUser->can('Replicate:Ficha');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Ficha');
    }
}
