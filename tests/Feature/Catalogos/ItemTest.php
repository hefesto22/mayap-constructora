<?php

declare(strict_types=1);

use App\Enums\CategoriaItem;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('código de item es único POR zona, NO global', function (): void {
    $srcZona = Zona::factory()->create(['codigo' => 'SRC']);
    $tguZona = Zona::factory()->create(['codigo' => 'TGU']);

    Item::factory()->enZona($srcZona)->create(['codigo' => 'CEM-50']);

    // Mismo código en otra zona DEBE permitirse
    $itemTgu = Item::factory()->enZona($tguZona)->create(['codigo' => 'CEM-50']);
    expect($itemTgu)->toBeInstanceOf(Item::class);

    // Mismo código en MISMA zona DEBE fallar
    expect(static fn () => Item::factory()->enZona($srcZona)->create(['codigo' => 'CEM-50']))
        ->toThrow(QueryException::class);
});

test('precio negativo es rechazado por CHECK constraint de Postgres', function (): void {
    expect(static fn () => Item::factory()->conPrecio(-1)->create())
        ->toThrow(QueryException::class);
});

test('categoría inválida es rechazada por el cast del enum (capa app)', function (): void {
    // El cast CategoriaItem::class en el modelo intercepta el valor antes
    // del INSERT — la consulta SQL ni siquiera llega a Postgres. PHP lanza
    // \ValueError porque el string no corresponde a ningún case del enum.
    expect(static fn () => Item::factory()->create(['categoria' => 'invalida_xxx']))
        ->toThrow(ValueError::class);
});

test('CHECK constraint Postgres rechaza categorías inválidas vía INSERT directo (capa DB)', function (): void {
    // Defensa en profundidad: incluso si alguien evita el modelo
    // (script SQL, importación cruda, otro lenguaje), Postgres bloquea.
    $zona = Zona::factory()->create();
    $unidad = UnidadMedida::factory()->create();

    expect(static fn () => DB::table('items')->insert([
        'zona_id'          => $zona->id,
        'unidad_medida_id' => $unidad->id,
        'categoria'        => 'invalida_xxx',
        'codigo'           => 'TEST-CHECK',
        'nombre'           => 'Item de prueba',
        'precio_unitario'  => 100,
        'activo'           => true,
        'created_at'       => now(),
        'updated_at'       => now(),
    ]))->toThrow(QueryException::class);
});

test('observer setea precio_actualizado_at al crear con precio > 0', function (): void {
    $item = Item::factory()->conPrecio(280)->create();

    expect($item->precio_actualizado_at)->not->toBeNull();
    expect($item->precio_actualizado_at->isToday())->toBeTrue();
});

test('observer NO setea precio_actualizado_at si precio es 0 al crear', function (): void {
    $item = Item::factory()->conPrecio(0)->create();

    expect($item->precio_actualizado_at)->toBeNull();
});

test('observer actualiza precio_actualizado_at SOLO cuando cambia el precio', function (): void {
    $item = Item::factory()->conPrecio(100)->create();
    $primerStamp = $item->precio_actualizado_at;

    // Esperar un segundo para que el timestamp pueda diferir
    $this->travel(2)->seconds();

    // Editar SIN cambiar precio: stamp NO debe moverse
    $item->update(['nombre' => 'Nuevo nombre, mismo precio']);
    expect($item->fresh()->precio_actualizado_at->equalTo($primerStamp))->toBeTrue();

    // Cambiar precio: stamp SÍ debe moverse
    $this->travel(2)->seconds();
    $item->update(['precio_unitario' => 150]);
    expect($item->fresh()->precio_actualizado_at->greaterThan($primerStamp))->toBeTrue();
});

test('scope deCategoria filtra correctamente', function (): void {
    Item::factory()->count(3)->deCategoria(CategoriaItem::Materiales)->create();
    Item::factory()->count(2)->deCategoria(CategoriaItem::ManoObra)->create();

    expect(Item::deCategoria(CategoriaItem::Materiales)->count())->toBe(3);
    expect(Item::deCategoria(CategoriaItem::ManoObra)->count())->toBe(2);
});

test('scope deZona filtra items de la zona indicada', function (): void {
    $src = Zona::factory()->create();
    $tgu = Zona::factory()->create();

    Item::factory()->count(4)->enZona($src)->create();
    Item::factory()->count(2)->enZona($tgu)->create();

    expect(Item::deZona($src->id)->count())->toBe(4);
    expect(Item::deZona($tgu->id)->count())->toBe(2);
});

test('scope preciosDesactualizados retorna items >N días sin actualizar', function (): void {
    // Item recién actualizado (no debe aparecer)
    Item::factory()->conPrecio(100)->create();

    // Item con stamp viejo (debe aparecer)
    $viejo = Item::factory()->conPrecio(50)->create();
    $viejo->forceFill(['precio_actualizado_at' => now()->subDays(120)])->save();

    // Item nunca actualizado (precio 0) — null debe contar como desactualizado
    Item::factory()->conPrecio(0)->create();

    expect(Item::preciosDesactualizados(90)->count())->toBe(2);
});

test('relación zona y unidadMedida cargan correctamente (con uppercase aplicado)', function (): void {
    $zona = Zona::factory()->create(['nombre' => 'Santa Rosa']);
    $unidad = UnidadMedida::factory()->create(['codigo' => 'BOLSA']);

    $item = Item::factory()->enZona($zona)->conUnidad($unidad)->create();

    // Los mutators del modelo aplican uppercase al persistir.
    expect($item->zona->nombre)->toBe('SANTA ROSA');
    expect($item->unidadMedida->codigo)->toBe('BOLSA');
});

test('cast de categoria devuelve enum tipado', function (): void {
    $item = Item::factory()->deCategoria(CategoriaItem::Materiales)->create();

    expect($item->categoria)->toBeInstanceOf(CategoriaItem::class);
    expect($item->categoria)->toBe(CategoriaItem::Materiales);
});
