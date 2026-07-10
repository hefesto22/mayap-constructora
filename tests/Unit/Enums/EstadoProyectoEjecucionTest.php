<?php

declare(strict_types=1);

use App\Enums\EstadoProyecto;

test('Aprobada puede iniciar ejecución o cancelarse, no finalizar directo', function (): void {
    expect(EstadoProyecto::Aprobada->puedeTransicionarA(EstadoProyecto::EnEjecucion))->toBeTrue();
    expect(EstadoProyecto::Aprobada->puedeTransicionarA(EstadoProyecto::Cancelada))->toBeTrue();
    expect(EstadoProyecto::Aprobada->puedeTransicionarA(EstadoProyecto::Finalizada))->toBeFalse();
});

test('En ejecución puede pausar, finalizar o cancelar', function (): void {
    expect(EstadoProyecto::EnEjecucion->puedeTransicionarA(EstadoProyecto::Pausada))->toBeTrue();
    expect(EstadoProyecto::EnEjecucion->puedeTransicionarA(EstadoProyecto::Finalizada))->toBeTrue();
    expect(EstadoProyecto::EnEjecucion->puedeTransicionarA(EstadoProyecto::Cancelada))->toBeTrue();
    expect(EstadoProyecto::EnEjecucion->puedeTransicionarA(EstadoProyecto::EnEjecucion))->toBeFalse();
});

test('Pausada puede reactivar, finalizar o cancelar', function (): void {
    expect(EstadoProyecto::Pausada->puedeTransicionarA(EstadoProyecto::EnEjecucion))->toBeTrue();
    expect(EstadoProyecto::Pausada->puedeTransicionarA(EstadoProyecto::Finalizada))->toBeTrue();
    expect(EstadoProyecto::Pausada->puedeTransicionarA(EstadoProyecto::Cancelada))->toBeTrue();
});

test('Finalizada y Cancelada son terminales', function (): void {
    expect(EstadoProyecto::Finalizada->esTerminal())->toBeTrue();
    expect(EstadoProyecto::Cancelada->esTerminal())->toBeTrue();
    expect(EstadoProyecto::Finalizada->transicionesPermitidas())->toBe([]);
    expect(EstadoProyecto::Cancelada->transicionesPermitidas())->toBe([]);
});

test('pausar y cancelar exigen motivo', function (): void {
    expect(EstadoProyecto::Pausada->requiereMotivo())->toBeTrue();
    expect(EstadoProyecto::Cancelada->requiereMotivo())->toBeTrue();
    expect(EstadoProyecto::Finalizada->requiereMotivo())->toBeFalse();
    expect(EstadoProyecto::EnEjecucion->requiereMotivo())->toBeFalse();
});

test('estados de ejecución requieren fecha de inicio', function (): void {
    expect(EstadoProyecto::EnEjecucion->requiereFechaInicio())->toBeTrue();
    expect(EstadoProyecto::Pausada->requiereFechaInicio())->toBeTrue();
    expect(EstadoProyecto::Finalizada->requiereFechaInicio())->toBeTrue();
    expect(EstadoProyecto::Cancelada->requiereFechaInicio())->toBeFalse();
    expect(EstadoProyecto::Aprobada->requiereFechaInicio())->toBeFalse();
});

test('solo Borrador permite editar renglones', function (): void {
    expect(EstadoProyecto::Borrador->permiteEditar())->toBeTrue();
    expect(EstadoProyecto::EnEjecucion->permiteEditar())->toBeFalse();
    expect(EstadoProyecto::Aprobada->permiteEditar())->toBeFalse();
});
