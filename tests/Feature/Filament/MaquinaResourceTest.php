<?php

declare(strict_types=1);

use App\Enums\EstadoMaquina;
use App\Enums\TipoMaquina;
use App\Filament\Resources\Maquinas\Pages\CreateMaquina;
use App\Filament\Resources\Maquinas\Pages\ListMaquinas;
use App\Models\Maquina;
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

test('MaquinaResource: lista renderiza sin error', function (): void {
    Maquina::factory()->count(3)->create();

    Livewire::test(ListMaquinas::class)->assertSuccessful();
});

test('MaquinaResource: crea una máquina con auto-código y nombre en mayúsculas', function (): void {
    Livewire::test(CreateMaquina::class)
        ->fillForm([
            'nombre'           => 'excavadora cat 320',
            'tipo'             => TipoMaquina::Excavadora->value,
            'horometro_actual' => '1200',
            'tarifa_hora'      => '1500',
            'jornada_horas'    => '8',
            'activo'           => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $maquina = Maquina::query()->firstOrFail();

    expect($maquina->codigo)->toBe('MAQ-00001')
        ->and($maquina->nombre)->toBe('EXCAVADORA CAT 320')
        ->and($maquina->tipo)->toBe(TipoMaquina::Excavadora)
        ->and($maquina->estado)->toBe(EstadoMaquina::Disponible);
});
