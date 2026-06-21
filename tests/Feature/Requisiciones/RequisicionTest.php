<?php

declare(strict_types=1);

use App\Enums\EstadoRequisicion;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\RequisicionTransicion;
use App\Models\User;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Tests de la capa de datos de requisiciones (modelos + CHECK + relaciones).
|--------------------------------------------------------------------------
| El avance de estado (Service) va en la siguiente sesión; aquí se valida
| que la estructura persiste correctamente y protege sus invariantes.
*/

test('requisición persiste con casts correctos', function (): void {
    $requisicion = Requisicion::factory()->create([
        'estado'          => EstadoRequisicion::Autorizada->value,
        'fecha_solicitud' => '2026-04-01',
        'fecha_necesaria' => '2026-04-07',
    ]);

    expect($requisicion->estado)->toBe(EstadoRequisicion::Autorizada)
        ->and($requisicion->fecha_solicitud->format('Y-m-d'))->toBe('2026-04-01')
        ->and($requisicion->fecha_necesaria->format('Y-m-d'))->toBe('2026-04-07');
});

test('auto-código REQ-AÑO-##### se genera con el año de fecha_solicitud', function (): void {
    $requisicion = Requisicion::factory()->create([
        'fecha_solicitud' => '2026-03-01',
        'fecha_necesaria' => '2026-03-10',
    ]);

    expect($requisicion->codigo)->toBe('REQ-2026-00001');
});

test('el contador de requisiciones se reinicia por año', function (): void {
    Requisicion::factory()->create(['fecha_solicitud' => '2026-05-01', 'fecha_necesaria' => '2026-05-02']);
    Requisicion::factory()->create(['fecha_solicitud' => '2026-05-01', 'fecha_necesaria' => '2026-05-02']);
    $tercera2027 = Requisicion::factory()->create(['fecha_solicitud' => '2027-01-10', 'fecha_necesaria' => '2027-01-15']);
    $cuarta2026 = Requisicion::factory()->create(['fecha_solicitud' => '2026-09-01', 'fecha_necesaria' => '2026-09-02']);

    expect($tercera2027->codigo)->toBe('REQ-2027-00001')
        ->and($cuarta2026->codigo)->toBe('REQ-2026-00003');
});

test('mutator uppercase aplica a notas', function (): void {
    $requisicion = Requisicion::factory()->create(['notas' => 'urgente para fundición']);

    expect($requisicion->notas)->toBe('URGENTE PARA FUNDICIÓN');
});

test('relaciones proyecto, solicitante, líneas y transiciones funcionan', function (): void {
    $proyecto = Proyecto::factory()->create();
    $user = User::factory()->create();
    $requisicion = Requisicion::factory()->create([
        'proyecto_id'    => $proyecto->id,
        'solicitante_id' => $user->id,
    ]);
    RequisicionLinea::factory()->count(2)->create(['requisicion_id' => $requisicion->id]);
    RequisicionTransicion::factory()->create(['requisicion_id' => $requisicion->id]);

    expect($requisicion->proyecto->id)->toBe($proyecto->id)
        ->and($requisicion->solicitante->id)->toBe($user->id)
        ->and($requisicion->lineas)->toHaveCount(2)
        ->and($requisicion->transiciones)->toHaveCount(1);
});

test('CHECK rechaza fecha_necesaria anterior a fecha_solicitud', function (): void {
    Requisicion::factory()->create([
        'fecha_solicitud' => '2026-06-10',
        'fecha_necesaria' => '2026-06-01',
    ]);
})->throws(QueryException::class);

test('CHECK rechaza cantidad_solicitada cero o negativa', function (): void {
    RequisicionLinea::factory()->create(['cantidad_solicitada' => 0]);
})->throws(QueryException::class);

test('un material no se repite dentro de la misma requisición', function (): void {
    $requisicion = Requisicion::factory()->create();
    $material = Material::factory()->create();

    RequisicionLinea::factory()->create(['requisicion_id' => $requisicion->id, 'material_id' => $material->id]);
    RequisicionLinea::factory()->create(['requisicion_id' => $requisicion->id, 'material_id' => $material->id]);
})->throws(QueryException::class);

test('borrar la requisición arrastra sus líneas y transiciones (cascade)', function (): void {
    $requisicion = Requisicion::factory()->create();
    RequisicionLinea::factory()->count(3)->create(['requisicion_id' => $requisicion->id]);
    RequisicionTransicion::factory()->count(2)->create(['requisicion_id' => $requisicion->id]);

    $requisicion->forceDelete();

    expect(RequisicionLinea::query()->where('requisicion_id', $requisicion->id)->count())->toBe(0)
        ->and(RequisicionTransicion::query()->where('requisicion_id', $requisicion->id)->count())->toBe(0);
});

test('FK restrict: proyecto con requisiciones no se puede eliminar', function (): void {
    $proyecto = Proyecto::factory()->create();
    Requisicion::factory()->create(['proyecto_id' => $proyecto->id]);

    $proyecto->forceDelete();
})->throws(QueryException::class);

test('scope activas excluye estados terminales', function (): void {
    Requisicion::factory()->enEstado(EstadoRequisicion::Solicitada)->create();
    Requisicion::factory()->enEstado(EstadoRequisicion::EnTransito)->create();
    Requisicion::factory()->enEstado(EstadoRequisicion::Cerrada)->create();
    Requisicion::factory()->enEstado(EstadoRequisicion::Rechazada)->create();

    expect(Requisicion::query()->activas()->count())->toBe(2);
});
