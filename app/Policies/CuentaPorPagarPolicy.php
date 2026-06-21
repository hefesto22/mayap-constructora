<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CuentaPorPagar;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CuentaPorPagarPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CuentaPorPagar');
    }

    public function view(AuthUser $authUser, CuentaPorPagar $cuentaPorPagar): bool
    {
        return $authUser->can('View:CuentaPorPagar');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CuentaPorPagar');
    }

    public function update(AuthUser $authUser, CuentaPorPagar $cuentaPorPagar): bool
    {
        return $authUser->can('Update:CuentaPorPagar');
    }

    public function delete(AuthUser $authUser, CuentaPorPagar $cuentaPorPagar): bool
    {
        return $authUser->can('Delete:CuentaPorPagar');
    }

    public function restore(AuthUser $authUser, CuentaPorPagar $cuentaPorPagar): bool
    {
        return $authUser->can('Restore:CuentaPorPagar');
    }

    public function forceDelete(AuthUser $authUser, CuentaPorPagar $cuentaPorPagar): bool
    {
        return $authUser->can('ForceDelete:CuentaPorPagar');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CuentaPorPagar');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CuentaPorPagar');
    }

    public function replicate(AuthUser $authUser, CuentaPorPagar $cuentaPorPagar): bool
    {
        return $authUser->can('Replicate:CuentaPorPagar');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CuentaPorPagar');
    }
}
