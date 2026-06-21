<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CuentaPorCobrar;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CuentaPorCobrarPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CuentaPorCobrar');
    }

    public function view(AuthUser $authUser, CuentaPorCobrar $cuentaPorCobrar): bool
    {
        return $authUser->can('View:CuentaPorCobrar');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CuentaPorCobrar');
    }

    public function update(AuthUser $authUser, CuentaPorCobrar $cuentaPorCobrar): bool
    {
        return $authUser->can('Update:CuentaPorCobrar');
    }

    public function delete(AuthUser $authUser, CuentaPorCobrar $cuentaPorCobrar): bool
    {
        return $authUser->can('Delete:CuentaPorCobrar');
    }

    public function restore(AuthUser $authUser, CuentaPorCobrar $cuentaPorCobrar): bool
    {
        return $authUser->can('Restore:CuentaPorCobrar');
    }

    public function forceDelete(AuthUser $authUser, CuentaPorCobrar $cuentaPorCobrar): bool
    {
        return $authUser->can('ForceDelete:CuentaPorCobrar');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CuentaPorCobrar');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CuentaPorCobrar');
    }

    public function replicate(AuthUser $authUser, CuentaPorCobrar $cuentaPorCobrar): bool
    {
        return $authUser->can('Replicate:CuentaPorCobrar');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CuentaPorCobrar');
    }
}
