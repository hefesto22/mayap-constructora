<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GastoMantenimiento;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GastoMantenimientoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:GastoMantenimiento');
    }

    public function view(AuthUser $authUser, GastoMantenimiento $gastoMantenimiento): bool
    {
        return $authUser->can('View:GastoMantenimiento');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:GastoMantenimiento');
    }

    public function update(AuthUser $authUser, GastoMantenimiento $gastoMantenimiento): bool
    {
        return $authUser->can('Update:GastoMantenimiento');
    }

    public function delete(AuthUser $authUser, GastoMantenimiento $gastoMantenimiento): bool
    {
        return $authUser->can('Delete:GastoMantenimiento');
    }

    public function restore(AuthUser $authUser, GastoMantenimiento $gastoMantenimiento): bool
    {
        return $authUser->can('Restore:GastoMantenimiento');
    }

    public function forceDelete(AuthUser $authUser, GastoMantenimiento $gastoMantenimiento): bool
    {
        return $authUser->can('ForceDelete:GastoMantenimiento');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:GastoMantenimiento');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:GastoMantenimiento');
    }

    public function replicate(AuthUser $authUser, GastoMantenimiento $gastoMantenimiento): bool
    {
        return $authUser->can('Replicate:GastoMantenimiento');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:GastoMantenimiento');
    }
}
