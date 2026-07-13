<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AgendaMaquina;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AgendaMaquinaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AgendaMaquina');
    }

    public function view(AuthUser $authUser, AgendaMaquina $agendaMaquina): bool
    {
        return $authUser->can('View:AgendaMaquina');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AgendaMaquina');
    }

    public function update(AuthUser $authUser, AgendaMaquina $agendaMaquina): bool
    {
        return $authUser->can('Update:AgendaMaquina');
    }

    public function delete(AuthUser $authUser, AgendaMaquina $agendaMaquina): bool
    {
        return $authUser->can('Delete:AgendaMaquina');
    }
}
