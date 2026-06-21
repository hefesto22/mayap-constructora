<?php

declare(strict_types=1);

use App\Enums\EstadoMantenimiento;
use App\Enums\EstadoMaquina;
use App\Filament\Resources\Mantenimientos\Pages\ListMantenimientos;
use App\Filament\Resources\Maquinas\Pages\ListMaquinas;
use App\Models\MantenimientoMaquina;
use App\Models\Maquina;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Maquinaria\AsignarMaquinaService;
use App\Services\Maquinaria\MantenimientoService;
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

test('MantenimientoMaquinaResource: lista renderiza sin error', function (): void {
    MantenimientoMaquina::factory()->count(3)->create();

    Livewire::test(ListMantenimientos::class)->assertSuccessful();
});

test('la acción Enviar a mantenimiento con sustituta finaliza la asignación y sustituye', function (): void {
    $obra = Proyecto::factory()->create();
    $averiada = Maquina::factory()->create();
    $sustituta = Maquina::factory()->create(['estado' => EstadoMaquina::Disponible->value]);

    app(AsignarMaquinaService::class)->asignar($averiada, $obra->id, tarifaPactada: '1500');

    Livewire::test(ListMaquinas::class)
        ->callTableAction('enviar_mantenimiento', $averiada, [
            'motivo'       => 'FALLA DE MOTOR',
            'sustituta_id' => $sustituta->id,
            'fecha'        => '2026-06-19',
        ])
        ->assertHasNoTableActionErrors();

    expect($averiada->fresh()->estado)->toBe(EstadoMaquina::Mantenimiento)
        ->and($sustituta->fresh()->estado)->toBe(EstadoMaquina::Asignada);

    $mantenimiento = MantenimientoMaquina::query()->firstOrFail();
    expect($mantenimiento->asignacion_sustituta_id)->not->toBeNull();
});

test('Enviar a mantenimiento no se ofrece en máquinas ya en mantenimiento', function (): void {
    $maquina = Maquina::factory()->enMantenimiento()->create();

    Livewire::test(ListMaquinas::class)
        ->assertTableActionHidden('enviar_mantenimiento', $maquina);
});

test('la acción Finalizar mantenimiento devuelve la máquina a disponible', function (): void {
    $maquina = Maquina::factory()->create();
    $mantenimiento = app(MantenimientoService::class)->enviarAMantenimiento($maquina, motivo: 'REVISIÓN');

    Livewire::test(ListMantenimientos::class)
        ->callTableAction('finalizar_mantenimiento', $mantenimiento, ['fecha_fin' => '2026-06-20'])
        ->assertHasNoTableActionErrors();

    expect($mantenimiento->fresh()->estado)->toBe(EstadoMantenimiento::Finalizado)
        ->and($maquina->fresh()->estado)->toBe(EstadoMaquina::Disponible);
});
