<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shield\Pages;

use App\Filament\Resources\Shield\RoleResource;

class ListRoles extends \BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles
{
    protected static string $resource = RoleResource::class;
}
