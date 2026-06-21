<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMaquina;
use App\Filament\Resources\AsignacionesMaquina\Pages\ListAsignacionesMaquina;
use App\Models\AsignacionMaquina;
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
});

test('AsignacionMaquinaResource: lista renderiza sin error', function (): void {
    AsignacionMaquina::factory()->count(3)->create();

    Livewire::test(ListAsignacionesMaquina::class)->assertSuccessful();
});

test('la acción Asignar vincula la máquina a la obra y la deja Asignada', function (): void {
    $maquina = Maquina::factory()->create(['tarifa_hora' => 1800]);
    $obra = Proyecto::factory()->create();

    Livewire::test(ListAsignacionesMaquina::class)
        ->callAction('asignar', [
            'maquina_id'          => $maquina->id,
            'proyecto_id'         => $obra->id,
            'tarifa_hora_pactada' => '2000',
            'fecha_inicio'        => '2026-06-19',
        ])
        ->assertHasNoActionErrors();

    $asignacion = AsignacionMaquina::query()->firstOrFail();

    expect($asignacion->tarifa_hora_pactada)->toBe('2000.00')
        ->and($asignacion->estado)->toBe(EstadoAsignacion::Activa)
        ->and($maquina->fresh()->estado)->toBe(EstadoMaquina::Asignada);
});

test('la acción Finalizar cierra la asignación y libera la máquina', function (): void {
    $maquina = Maquina::factory()->create();
    $obra = Proyecto::factory()->create();
    $asignacion = app(AsignarMaquinaService::class)->asignar($maquina, $obra->id);

    Livewire::test(ListAsignacionesMaquina::class)
        ->callTableAction('finalizar', $asignacion, ['fecha_fin' => '2026-06-20'])
        ->assertHasNoTableActionErrors();

    expect($asignacion->fresh()->estado)->toBe(EstadoAsignacion::Finalizada)
        ->and($maquina->fresh()->estado)->toBe(EstadoMaquina::Disponible);
});

test('Finalizar no se ofrece en asignaciones ya finalizadas', function (): void {
    $asignacion = AsignacionMaquina::factory()->finalizada()->create();

    Livewire::test(ListAsignacionesMaquina::class)
        ->assertTableActionHidden('finalizar', $asignacion);
});
