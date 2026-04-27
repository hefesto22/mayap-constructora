<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\UnidadMedida;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class UnidadMedidaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:UnidadMedida');
    }

    public function view(AuthUser $authUser, UnidadMedida $unidadMedida): bool
    {
        return $authUser->can('View:UnidadMedida');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:UnidadMedida');
    }

    public function update(AuthUser $authUser, UnidadMedida $unidadMedida): bool
    {
        return $authUser->can('Update:UnidadMedida');
    }

    public function delete(AuthUser $authUser, UnidadMedida $unidadMedida): bool
    {
        return $authUser->can('Delete:UnidadMedida');
    }

    public function restore(AuthUser $authUser, UnidadMedida $unidadMedida): bool
    {
        return $authUser->can('Restore:UnidadMedida');
    }

    public function forceDelete(AuthUser $authUser, UnidadMedida $unidadMedida): bool
    {
        return $authUser->can('ForceDelete:UnidadMedida');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:UnidadMedida');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:UnidadMedida');
    }

    public function replicate(AuthUser $authUser, UnidadMedida $unidadMedida): bool
    {
        return $authUser->can('Replicate:UnidadMedida');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:UnidadMedida');
    }
}
