<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Planilla;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PlanillaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Planilla');
    }

    public function view(AuthUser $authUser, Planilla $planilla): bool
    {
        return $authUser->can('View:Planilla');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Planilla');
    }

    public function update(AuthUser $authUser, Planilla $planilla): bool
    {
        return $authUser->can('Update:Planilla');
    }

    public function delete(AuthUser $authUser, Planilla $planilla): bool
    {
        return $authUser->can('Delete:Planilla');
    }

    public function restore(AuthUser $authUser, Planilla $planilla): bool
    {
        return $authUser->can('Restore:Planilla');
    }

    public function forceDelete(AuthUser $authUser, Planilla $planilla): bool
    {
        return $authUser->can('ForceDelete:Planilla');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Planilla');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Planilla');
    }

    public function replicate(AuthUser $authUser, Planilla $planilla): bool
    {
        return $authUser->can('Replicate:Planilla');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Planilla');
    }
}
