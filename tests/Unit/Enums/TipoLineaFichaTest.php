<?php

declare(strict_types=1);

use App\Enums\TipoLineaFicha;

test('enum tiene exactamente 2 tipos: item y porcentaje', function (): void {
    expect(TipoLineaFicha::cases())->toHaveCount(2);
});

test('valores del enum coinciden con los CHECK constraints de la tabla ficha_lineas', function (): void {
    $valores = array_map(static fn (TipoLineaFicha $t): string => $t->value, TipoLineaFicha::cases());

    expect($valores)->toBe(['item', 'porcentaje']);
});

test('cada tipo tiene label, color e icono no vacíos', function (TipoLineaFicha $tipo): void {
    expect($tipo->getLabel())->toBeString()->not->toBeEmpty();
    expect($tipo->getColor())->toBeString()->not->toBeEmpty();
    expect($tipo->getIcon())->toBeString()->not->toBeEmpty();
})->with(TipoLineaFicha::cases());

test('options() devuelve mapa value => label de los 2 casos', function (): void {
    $options = TipoLineaFicha::options();

    expect($options)
        ->toHaveCount(2)
        ->toHaveKey('item')
        ->toHaveKey('porcentaje');
});
