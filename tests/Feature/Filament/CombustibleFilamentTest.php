<?php

declare(strict_types=1);

use App\Filament\Resources\AsignacionesMaquina\Pages\ListAsignacionesMaquina;
use App\Models\ConsumoCombustible;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Maquinaria\AsignarMaquinaService;
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

    $this->obra = Proyecto::factory()->create();
    $maquina = Maquina::factory()->create();
    $this->asignacion = app(AsignarMaquinaService::class)->asignar($maquina, $this->obra->id);
});

test('la acción Registrar combustible crea el consumo y calcula el costo', function (): void {
    Livewire::test(ListAsignacionesMaquina::class)
        ->callTableAction('registrar_combustible', $this->asignacion, [
            'cantidad_litros' => '50',
            'precio_litro'    => '110.50',
            'fecha'           => '2026-06-19',
        ])
        ->assertHasNoTableActionErrors();

    $consumo = ConsumoCombustible::query()->firstOrFail();

    expect($consumo->cantidad_litros)->toBe('50.00')
        ->and($consumo->costo_cache)->toBe('5525.00');
});

test('Registrar combustible no se ofrece en asignaciones finalizadas', function (): void {
    app(AsignarMaquinaService::class)->finalizar($this->asignacion);

    Livewire::test(ListAsignacionesMaquina::class)
        ->assertTableActionHidden('registrar_combustible', $this->asignacion->fresh());
});
