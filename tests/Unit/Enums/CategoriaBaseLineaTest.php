<?php

declare(strict_types=1);

use App\Enums\CategoriaBaseLinea;

test('enum tiene 4 bases: 3 categorías directas + costo_directo agregado', function (): void {
    expect(CategoriaBaseLinea::cases())->toHaveCount(4);
});

test('valores coinciden con CHECK constraint categoria_base de ficha_lineas', function (): void {
    $valores = array_map(static fn (CategoriaBaseLinea $c): string => $c->value, CategoriaBaseLinea::cases());

    expect($valores)->toBe(['materiales', 'mano_obra', 'herramienta_equipo', 'costo_directo']);
});

test('NO incluye indirectos como base — no tiene sentido aplicar % sobre indirectos', function (): void {
    $valores = array_map(static fn (CategoriaBaseLinea $c): string => $c->value, CategoriaBaseLinea::cases());

    expect($valores)->not->toContain('indirectos');
});

test('esAgregada() identifica solo costo_directo como base agregada', function (): void {
    expect(CategoriaBaseLinea::CostoDirecto->esAgregada())->toBeTrue();

    expect(CategoriaBaseLinea::Materiales->esAgregada())->toBeFalse();
    expect(CategoriaBaseLinea::ManoObra->esAgregada())->toBeFalse();
    expect(CategoriaBaseLinea::HerramientaEquipo->esAgregada())->toBeFalse();
});

test('cada base tiene label descriptivo no vacío', function (CategoriaBaseLinea $base): void {
    expect($base->getLabel())->toBeString()->not->toBeEmpty();
})->with(CategoriaBaseLinea::cases());
