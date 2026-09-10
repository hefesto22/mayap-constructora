<?php

declare(strict_types=1);

use App\Enums\EstadoAsignacion;
use App\Models\AsignacionMaquina;
use App\Models\Empleado;
use App\Models\Maquina;
use App\Models\Operador;
use App\Models\Proyecto;
use App\Services\Maquinaria\RegistrarDiaMaquinaService;
use App\Services\Maquinaria\RegistrarParteService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Operadores — la persona que maneja, esté o no en planilla
|--------------------------------------------------------------------------
| "A veces el operador sí es empleado pero otras veces es externo"
| (Mauricio 2026-09-05). El catálogo es propio y el vínculo con planilla
| es opcional; la máquina guarda su operador habitual y el parte lo trae
| puesto, guardando además el NOMBRE de ese día como foto.
*/

test('GOLDEN: el operador puede ser de planilla o externo, y ambos son igual de válidos', function (): void {
    $empleado = Empleado::factory()->create(['nombre' => 'JOSE RAMIREZ']);

    $dePlanilla = Operador::create(['nombre' => 'jose ramirez', 'empleado_id' => $empleado->id]);
    $externo = Operador::create(['nombre' => 'don chepe']);

    expect($dePlanilla->nombre)->toBe('JOSE RAMIREZ')          // se normaliza
        ->and($dePlanilla->esExterno())->toBeFalse()
        ->and($dePlanilla->empleado->id)->toBe($empleado->id)
        ->and($externo->esExterno())->toBeTrue()
        ->and($externo->etiqueta())->toBe('DON CHEPE (externo)')
        ->and($dePlanilla->codigo)->toStartWith('OPE-')
        ->and($externo->codigo)->not->toBe($dePlanilla->codigo);
});

test('el parte guarda el vínculo Y el nombre del día: renombrar después no reescribe la historia', function (): void {
    $operador = Operador::create(['nombre' => 'MARIO LOPEZ']);
    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create(['horometro_actual' => '100.00', 'tarifa_hora' => '500.00']);

    $asignacion = AsignacionMaquina::factory()->create([
        'maquina_id'          => $maquina->id,
        'proyecto_id'         => $obra->id,
        'estado'              => EstadoAsignacion::Activa->value,
        'tarifa_hora_pactada' => '500.00',
    ]);

    $parte = app(RegistrarParteService::class)->registrarManual(
        asignacion: $asignacion,
        horas: '8',
        operadorId: $operador->id,
    );

    expect($parte->operador_id)->toBe($operador->id)
        ->and($parte->operador)->toBe('MARIO LOPEZ')
        ->and($parte->operadorRegistrado->nombre)->toBe('MARIO LOPEZ');

    // Le corrigen el nombre en el catálogo: el parte viejo no se toca.
    $operador->update(['nombre' => 'MARIO ANTONIO LOPEZ']);

    expect($parte->fresh()->operador)->toBe('MARIO LOPEZ')
        ->and($parte->fresh()->operadorRegistrado->nombre)->toBe('MARIO ANTONIO LOPEZ');
});

test('la captura del día propone el operador habitual de cada máquina', function (): void {
    $operador = Operador::create(['nombre' => 'CARLOS MEJIA']);
    $obra = Proyecto::factory()->enEjecucion()->create();
    $maquina = Maquina::factory()->create(['operador_habitual_id' => $operador->id]);

    AsignacionMaquina::factory()->create([
        'maquina_id'  => $maquina->id,
        'proyecto_id' => $obra->id,
        'estado'      => EstadoAsignacion::Activa->value,
    ]);

    $filas = app(RegistrarDiaMaquinaService::class)->filasDelDia(today()->toDateString());

    expect($filas)->toHaveCount(1)
        ->and($filas[0]['operador_id'])->toBe($operador->id);
});

test('un empleado no se puede registrar dos veces como operador', function (): void {
    $empleado = Empleado::factory()->create();
    Operador::create(['nombre' => 'PRIMERO', 'empleado_id' => $empleado->id]);

    expect(fn () => Operador::create(['nombre' => 'SEGUNDO', 'empleado_id' => $empleado->id]))
        ->toThrow(QueryException::class);
});
