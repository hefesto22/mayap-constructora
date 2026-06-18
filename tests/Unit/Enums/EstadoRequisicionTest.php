<?php

declare(strict_types=1);

use App\Enums\EstadoRequisicion;

/*
|--------------------------------------------------------------------------
| Tests de la máquina de estados de requisiciones (lógica pura, sin DB).
|--------------------------------------------------------------------------
*/

test('Solicitada solo transiciona a Autorizada o Rechazada', function (): void {
    expect(EstadoRequisicion::Solicitada->transicionesPermitidas())
        ->toBe([EstadoRequisicion::Autorizada, EstadoRequisicion::Rechazada]);
});

test('Autorizada transiciona a Despachada, RequisicionCompra o Rechazada', function (): void {
    expect(EstadoRequisicion::Autorizada->transicionesPermitidas())
        ->toBe([
            EstadoRequisicion::Despachada,
            EstadoRequisicion::RequisicionCompra,
            EstadoRequisicion::Rechazada,
        ]);
});

test('RequisicionCompra puede ir a Despachada cuando entra el stock', function (): void {
    expect(EstadoRequisicion::RequisicionCompra->puedeTransicionarA(EstadoRequisicion::Despachada))
        ->toBeTrue();
});

test('Recibida transiciona a Cerrada o Discrepancia', function (): void {
    expect(EstadoRequisicion::Recibida->transicionesPermitidas())
        ->toBe([EstadoRequisicion::Cerrada, EstadoRequisicion::Discrepancia]);
});

test('los estados terminales no tienen transiciones de salida', function (EstadoRequisicion $estado): void {
    expect($estado->esTerminal())->toBeTrue()
        ->and($estado->transicionesPermitidas())->toBe([]);
})->with([
    'cerrada'      => [EstadoRequisicion::Cerrada],
    'discrepancia' => [EstadoRequisicion::Discrepancia],
    'rechazada'    => [EstadoRequisicion::Rechazada],
]);

test('puedeTransicionarA rechaza saltos inválidos', function (): void {
    expect(EstadoRequisicion::Solicitada->puedeTransicionarA(EstadoRequisicion::Despachada))->toBeFalse()
        ->and(EstadoRequisicion::Despachada->puedeTransicionarA(EstadoRequisicion::Recibida))->toBeFalse()
        ->and(EstadoRequisicion::EnTransito->puedeTransicionarA(EstadoRequisicion::Recibida))->toBeTrue();
});

test('solo Solicitada permite editar líneas', function (): void {
    expect(EstadoRequisicion::Solicitada->permiteEditarLineas())->toBeTrue()
        ->and(EstadoRequisicion::Autorizada->permiteEditarLineas())->toBeFalse()
        ->and(EstadoRequisicion::Despachada->permiteEditarLineas())->toBeFalse();
});

test('options retorna todos los estados con su label', function (): void {
    $options = EstadoRequisicion::options();

    expect($options)->toHaveCount(9)
        ->and($options['solicitada'])->toBe('Solicitada')
        ->and($options['requisicion_compra'])->toBe('Requisición de compra');
});
