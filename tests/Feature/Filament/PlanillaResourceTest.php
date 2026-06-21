<?php

declare(strict_types=1);

use App\Enums\EstadoPlanilla;
use App\Enums\TipoPago;
use App\Filament\Resources\Planillas\Pages\ListPlanillas;
use App\Models\Empleado;
use App\Models\Planilla;
use App\Models\PlanillaLinea;
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

test('PlanillaResource: lista renderiza sin error', function (): void {
    Planilla::factory()->count(3)->create();

    Livewire::test(ListPlanillas::class)->assertSuccessful();
});

test('la acción Cerrar calcula montos y marca la planilla cerrada', function (): void {
    $planilla = Planilla::factory()->create();
    $empleado = Empleado::factory()->create(['tipo_pago' => TipoPago::Jornal->value]);
    PlanillaLinea::factory()->create([
        'planilla_id'     => $planilla->id,
        'empleado_id'     => $empleado->id,
        'tipo_pago'       => TipoPago::Jornal->value,
        'dias_trabajados' => 6,
        'tarifa_aplicada' => 500,
        'monto_bruto'     => 0,
    ]);

    Livewire::test(ListPlanillas::class)
        ->callTableAction('cerrar', $planilla)
        ->assertHasNoTableActionErrors();

    $planilla->refresh();
    expect($planilla->estado)->toBe(EstadoPlanilla::Cerrada)
        ->and($planilla->total_cache)->toBe('3000.00');
});

test('Cerrar no se ofrece en planillas ya cerradas', function (): void {
    $planilla = Planilla::factory()->cerrada()->create();

    Livewire::test(ListPlanillas::class)
        ->assertTableActionHidden('cerrar', $planilla);
});
