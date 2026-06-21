<?php

declare(strict_types=1);

use App\Enums\TipoPago;
use App\Filament\Resources\Empleados\Pages\CreateEmpleado;
use App\Filament\Resources\Empleados\Pages\ListEmpleados;
use App\Models\Empleado;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => Utils::getSuperAdminName(), 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => Utils::getPanelUserRoleName(), 'guard_name' => 'web']);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole(Utils::getSuperAdminName());

    Gate::before(function ($user): ?bool {
        return $user instanceof User && $user->hasRole(Utils::getSuperAdminName())
            ? true
            : null;
    });

    $this->actingAs($this->admin);
});

test('EmpleadoResource: lista renderiza sin error', function (): void {
    Empleado::factory()->count(3)->create();

    Livewire::test(ListEmpleados::class)->assertSuccessful();
});

test('EmpleadoResource: crea un empleado con auto-código y nombre en mayúsculas', function (): void {
    Livewire::test(CreateEmpleado::class)
        ->fillForm([
            'nombre'      => 'juan pérez',
            'cargo'       => 'albañil',
            'tipo_pago'   => TipoPago::Jornal->value,
            'tarifa_base' => '500',
            'activo'      => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $empleado = Empleado::query()->firstOrFail();

    expect($empleado->codigo)->toBe('EMP-00001')
        ->and($empleado->nombre)->toBe('JUAN PÉREZ')
        ->and($empleado->tipo_pago)->toBe(TipoPago::Jornal);
});
