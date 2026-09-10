<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Operador;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OperadorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Operador');
    }

    public function view(AuthUser $authUser, Operador $operador): bool
    {
        return $authUser->can('View:Operador');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Operador');
    }

    public function update(AuthUser $authUser, Operador $operador): bool
    {
        return $authUser->can('Update:Operador');
    }

    public function delete(AuthUser $authUser, Operador $operador): bool
    {
        return $authUser->can('Delete:Operador');
    }

    public function restore(AuthUser $authUser, Operador $operador): bool
    {
        return $authUser->can('Restore:Operador');
    }

    public function forceDelete(AuthUser $authUser, Operador $operador): bool
    {
        return $authUser->can('ForceDelete:Operador');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Operador');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Operador');
    }

    public function replicate(AuthUser $authUser, Operador $operador): bool
    {
        return $authUser->can('Replicate:Operador');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Operador');
    }
}
