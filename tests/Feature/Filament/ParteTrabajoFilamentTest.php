<?php

declare(strict_types=1);

use App\Enums\MetodoCapturaHoras;
use App\Filament\Resources\AsignacionesMaquina\Pages\ListAsignacionesMaquina;
use App\Filament\Resources\PartesTrabajo\Pages\ListPartesTrabajo;
use App\Models\Maquina;
use App\Models\ParteTrabajo;
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
});

test('ParteTrabajoResource: lista global renderiza sin error', function (): void {
    ParteTrabajo::factory()->count(3)->create();

    Livewire::test(ListPartesTrabajo::class)->assertSuccessful();
});

test('la acción Registrar parte (horómetro) crea el parte y avanza el horómetro', function (): void {
    $maquina = Maquina::factory()->create(['horometro_actual' => 100, 'jornada_horas' => 8]);
    $asignacion = app(AsignarMaquinaService::class)->asignar($maquina, $this->obra->id, tarifaPactada: '1500');

    Livewire::test(ListAsignacionesMaquina::class)
        ->callTableAction('registrar_parte', $asignacion, [
            'metodo_captura'  => MetodoCapturaHoras::Horometro->value,
            'lectura_inicial' => '100',
            'lectura_final'   => '108',
            'fecha'           => '2026-06-19',
        ])
        ->assertHasNoTableActionErrors();

    $parte = ParteTrabajo::query()->firstOrFail();

    expect($parte->horas)->toBe('8.00')
        ->and($parte->costo_cache)->toBe('12000.00')
        ->and($maquina->fresh()->horometro_actual)->toBe('108.00');
});

test('la acción Registrar parte (manual) crea el parte sin tocar el horómetro', function (): void {
    $maquina = Maquina::factory()->create(['horometro_actual' => 300, 'jornada_horas' => 8]);
    $asignacion = app(AsignarMaquinaService::class)->asignar($maquina, $this->obra->id, tarifaPactada: '1000');

    Livewire::test(ListAsignacionesMaquina::class)
        ->callTableAction('registrar_parte', $asignacion, [
            'metodo_captura' => MetodoCapturaHoras::Manual->value,
            'horas'          => '5',
            'fecha'          => '2026-06-19',
        ])
        ->assertHasNoTableActionErrors();

    expect(ParteTrabajo::query()->firstOrFail()->costo_cache)->toBe('5000.00')
        ->and($maquina->fresh()->horometro_actual)->toBe('300.00');
});

test('Registrar parte no se ofrece en asignaciones finalizadas', function (): void {
    $maquina = Maquina::factory()->create();
    $asignacion = app(AsignarMaquinaService::class)->asignar($maquina, $this->obra->id);
    app(AsignarMaquinaService::class)->finalizar($asignacion);

    Livewire::test(ListAsignacionesMaquina::class)
        ->assertTableActionHidden('registrar_parte', $asignacion->fresh());
});
