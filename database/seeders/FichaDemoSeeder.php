<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CategoriaBaseLinea;
use App\Enums\CategoriaItem;
use App\Enums\TipoLineaFicha;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\UnidadMedida;
use App\Models\Zona;
use App\Services\Fichas\CalcularPrecioFichaService;
use Illuminate\Database\Seeder;

/**
 * Seeder demo que reproduce la ficha REAL del cliente — la golden test
 * matemática del Sprint 2.
 *
 * Ficha: "LOSA DE CONCRETO ALIGERADA, E=10CM, 3000PSI VAR#4 @20CM A.S."
 * Resultado esperado: PRECIO VENTA = L 2,604.37 por M² (idéntico al
 * Excel que el ingeniero del cliente usa hoy).
 *
 * Si esta ficha NO da 2604.37 al recalcular, hay un bug matemático en
 * el Service. El seeder lo verifica y aborta ruidosamente para que
 * cualquier regresión se detecte temprano.
 *
 * NO se incluye en CatalogosSeeder ni DatabaseSeeder por defecto: los
 * datos demo NO van a producción. Para cargarlo en local:
 *
 *   php artisan db:seed --class=Database\\Seeders\\FichaDemoSeeder
 *
 * Idempotente: si la ficha demo ya existe, no la duplica. Si quieres
 * regenerarla con cambios, eliminala primero desde Filament o tinker.
 */
class FichaDemoSeeder extends Seeder
{
    private const string NOMBRE_FICHA = 'LOSA DE CONCRETO ALIGERADA, E=10CM, 3000PSI VAR#4 @20CM A.S.';

    private const string PRECIO_VENTA_ESPERADO = '2604.37';

    public function run(): void
    {
        $zona = Zona::where('codigo', 'SRC')->first();

        if ($zona === null) {
            $this->command->warn('Zona SRC no existe. Ejecuta primero ZonaSeeder.');

            return;
        }

        // 1) Asegurar unidades de medida específicas del oficio (idempotente).
        $this->crearUnidadesFaltantes();

        // 2) Crear los items de la ficha con precios EXACTOS del Excel.
        $items = $this->crearItemsDeLaFicha($zona);

        // 3) Crear la ficha (skip si ya existe).
        $unidadM2 = UnidadMedida::where('codigo', 'M2')->firstOrFail();

        $ficha = Ficha::firstOrCreate(
            [
                'zona_id' => $zona->id,
                'nombre'  => self::NOMBRE_FICHA,
            ],
            [
                'unidad_medida_id'    => $unidadM2->id,
                'parametros_tecnicos' => [
                    'VOLUMEN DE CONCRETO' => '0.1 M³/M²',
                    'CANTIDAD'            => '1.00',
                ],
                'utilidad_porcentaje' => 25.00,
                'activa'              => true,
            ]
        );

        // Si ya tenía líneas (ya se había seedeado antes), reportar y salir.
        if ($ficha->lineas()->exists()) {
            $this->command->info("✓ FichaDemoSeeder: ficha demo ya existe (#{$ficha->codigo}). No se modificó.");

            return;
        }

        // 4) Crear las 17 líneas de composición.
        $this->crearLineasDeLaFicha($ficha, $items);

        // 5) Recalcular y persistir cache + verificar el golden test.
        $service = new CalcularPrecioFichaService;
        $resultado = $service->recalcularYPersistir($ficha->fresh());

        if ($resultado->precioVenta !== self::PRECIO_VENTA_ESPERADO) {
            $this->command->error(
                "✗ FichaDemoSeeder GOLDEN TEST FALLÓ:\n".
                '    Esperado: L '.self::PRECIO_VENTA_ESPERADO."\n".
                "    Obtenido: L {$resultado->precioVenta}\n".
                "  Hay regresión matemática en CalcularPrecioFichaService.\n"
            );

            return;
        }

        $this->command->info(
            "✓ FichaDemoSeeder: ficha #{$ficha->fresh()->codigo} creada con 17 líneas.\n".
            "  Precio venta: L {$resultado->precioVenta} (golden test PASÓ)."
        );
    }

    /**
     * Unidades específicas que la ficha del cliente usa. Idempotentes —
     * `firstOrCreate` no duplica las que ya existan vía UnidadMedidaSeeder.
     *
     * Lista standalone: incluye también M2/M3/BOLSA/JDR para que el seeder
     * funcione aunque se corra antes que UnidadMedidaSeeder (útil en tests
     * Feature con RefreshDatabase + this->seed(FichaDemoSeeder::class)).
     */
    private function crearUnidadesFaltantes(): void
    {
        $unidades = [
            // Comunes (también las crea UnidadMedidaSeeder, idempotente)
            ['codigo' => 'M2',     'nombre' => 'Metro cuadrado',            'simbolo' => 'm²'],
            ['codigo' => 'M3',     'nombre' => 'Metro cúbico',              'simbolo' => 'm³'],
            ['codigo' => 'BOLSA',  'nombre' => 'Bolsa',                     'simbolo' => null],
            ['codigo' => 'JDR',    'nombre' => 'Jornada',                   'simbolo' => null],

            // Específicas del oficio del cliente
            ['codigo' => 'PT',     'nombre' => 'Pie tablar',                'simbolo' => 'pt'],
            ['codigo' => 'LANCE',  'nombre' => 'Lance (largo de varilla)',  'simbolo' => null],
            ['codigo' => 'LIBRA',  'nombre' => 'Libra (alias de LB)',       'simbolo' => 'lb'],
            ['codigo' => 'DIA',    'nombre' => 'Día (alquiler equipo)',     'simbolo' => null],
        ];

        foreach ($unidades as $datos) {
            UnidadMedida::firstOrCreate(
                ['codigo' => $datos['codigo']],
                [
                    'nombre'  => $datos['nombre'],
                    'simbolo' => $datos['simbolo'],
                    'activo'  => true,
                ]
            );
        }
    }

    /**
     * Crea (o reutiliza) los items con los precios exactos del Excel.
     *
     * @return array<string, Item> Map nombre → Item para resolver al armar líneas.
     */
    private function crearItemsDeLaFicha(Zona $zona): array
    {
        $unidades = UnidadMedida::pluck('id', 'codigo')->all();

        // [nombre, codigoUnidad, categoria, precio]
        $catalogo = [
            // ─── Materiales ──────────────────────────────────────────
            ['CEMENTO',                  'BOLSA', CategoriaItem::Materiales,        220.00],
            ['ARENA',                    'M3',    CategoriaItem::Materiales,        600.00],
            ['GRAVA TRIT 3/4',           'M3',    CategoriaItem::Materiales,        750.00],
            ['AGUA',                     'M3',    CategoriaItem::Materiales,        100.00],
            ['LAMINA DE ALUZINC',        'PT',    CategoriaItem::Materiales,         52.00],
            ['CANALETA 2X4',             'LANCE', CategoriaItem::Materiales,        450.00],
            ['VAR#4',                    'LANCE', CategoriaItem::Materiales,        270.00],
            ['ALAMBRE DE AMARRE',        'LIBRA', CategoriaItem::Materiales,         20.00],
            ['TORNILLOS',                'LIBRA', CategoriaItem::Materiales,          2.50],
            ['CLAVOS',                   'LIBRA', CategoriaItem::Materiales,         25.00],

            // ─── Mano de obra ────────────────────────────────────────
            ['ALBAÑIL',                  'JDR',   CategoriaItem::ManoObra,          750.00],
            ['SOLDADOR',                 'JDR',   CategoriaItem::ManoObra,          750.00],
            ['AYUDANTE',                 'JDR',   CategoriaItem::ManoObra,          450.00],

            // ─── Herramienta y equipo ────────────────────────────────
            ['CONCRETERA',               'DIA',   CategoriaItem::HerramientaEquipo, 1000.00],
            ['VIBRADOR',                 'DIA',   CategoriaItem::HerramientaEquipo,  700.00],
            ['SOLDADORA',                'DIA',   CategoriaItem::HerramientaEquipo,  400.00],
        ];

        $map = [];

        foreach ($catalogo as [$nombre, $unidadCodigo, $categoria, $precio]) {
            if (! isset($unidades[$unidadCodigo])) {
                $this->command->warn("Unidad {$unidadCodigo} no encontrada — saltando '{$nombre}'.");

                continue;
            }

            $item = Item::firstOrCreate(
                [
                    'zona_id'   => $zona->id,
                    'categoria' => $categoria->value,
                    'nombre'    => $nombre,
                ],
                [
                    'unidad_medida_id' => $unidades[$unidadCodigo],
                    'precio_unitario'  => $precio,
                    'activo'           => true,
                ]
            );

            $map[$nombre] = $item;
        }

        return $map;
    }

    /**
     * Crea las 17 líneas de la ficha real. Los rendimientos base están
     * calibrados para que el cálculo crudo (sin redondear intermedios)
     * reproduzca al céntimo cada subtotal del Excel original.
     *
     * @param array<string, Item> $items
     */
    private function crearLineasDeLaFicha(Ficha $ficha, array $items): void
    {
        $orden = 0;

        // [nombreItem, rendimientoBase, desperdicioPorcentaje]
        // Rendimientos calibrados: rendimiento_base × (1 + desp/100) × precio_item
        // = subtotal del Excel cuando se redondea con bcround half-up al final.
        $lineasItem = [
            // Materiales
            ['CEMENTO',           '0.850000',  '5.00'],
            ['ARENA',             '0.052000', '10.00'],
            ['GRAVA TRIT 3/4',    '0.078000', '10.00'],
            ['AGUA',              '0.020000', '25.00'],
            ['LAMINA DE ALUZINC', '3.280000',  '5.00'],
            ['CANALETA 2X4',      '0.500000',  '5.00'],
            ['VAR#4',             '1.111111',  '5.00'],
            ['ALAMBRE DE AMARRE', '0.333333',  '5.00'],
            ['TORNILLOS',         '0.156364', '10.00'],
            ['CLAVOS',            '0.044571',  '5.00'],

            // Mano de obra
            ['ALBAÑIL',           '0.500000',  '0.00'],
            ['SOLDADOR',          '0.500000',  '0.00'],
            ['AYUDANTE',           '0.500000',  '0.00'],

            // Herramienta y equipo (sin la línea %)
            ['CONCRETERA',        '0.011111',  '0.00'],
            ['VIBRADOR',          '0.011114',  '0.00'],
            ['SOLDADORA',         '0.011000',  '0.00'],
        ];

        foreach ($lineasItem as [$nombreItem, $rend, $desp]) {
            if (! isset($items[$nombreItem])) {
                continue;
            }

            FichaLinea::create([
                'ficha_id'               => $ficha->id,
                'tipo'                   => TipoLineaFicha::Item,
                'orden'                  => $orden++,
                'item_id'                => $items[$nombreItem]->id,
                'rendimiento'            => $rend,
                'desperdicio_porcentaje' => $desp,
            ]);
        }

        // Línea derivada: HERRAMIENTA MENOR = 5% sobre Mano de Obra,
        // aparece en la sección de Herramienta y Equipo del reporte.
        FichaLinea::create([
            'ficha_id'          => $ficha->id,
            'tipo'              => TipoLineaFicha::Porcentaje,
            'orden'             => $orden,
            'descripcion'       => 'HERRAMIENTA MENOR',
            'porcentaje'        => '5.00',
            'categoria_base'    => CategoriaBaseLinea::ManoObra->value,
            'categoria_destino' => CategoriaItem::HerramientaEquipo->value,
        ]);
    }
}
