<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;

test('enum tiene exactamente 4 categorías canónicas del APU', function (): void {
    expect(CategoriaItem::cases())->toHaveCount(4);
});

test('todas las categorías tienen valor distinto y consistente con DB', function (): void {
    $valores = array_map(static fn (CategoriaItem $c): string => $c->value, CategoriaItem::cases());

    expect($valores)->toBe(['materiales', 'mano_obra', 'herramienta_equipo', 'indirectos']);
    expect(array_unique($valores))->toHaveCount(4);
});

test('cada categoría tiene label, color e icono no vacíos', function (CategoriaItem $categoria): void {
    expect($categoria->getLabel())->toBeString()->not->toBeEmpty();
    expect($categoria->getColor())->toBeString()->not->toBeEmpty();
    expect($categoria->getIcon())->toBeString()->not->toBeEmpty();
})->with(CategoriaItem::cases());

test('options() devuelve mapa value => label de los 4 casos', function (): void {
    $options = CategoriaItem::options();

    expect($options)
        ->toHaveCount(4)
        ->toHaveKey('materiales')
        ->toHaveKey('mano_obra')
        ->toHaveKey('herramienta_equipo')
        ->toHaveKey('indirectos');

    expect($options['materiales'])->toBe('Materiales');
});
