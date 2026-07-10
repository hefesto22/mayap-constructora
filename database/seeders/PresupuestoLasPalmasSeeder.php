<?php

declare(strict_types=1);

namespace Database\Seeders;

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
 * Seeder demo: 9 fichas APU en la zona Santa Rosa (SRC) para reproducir
 * manualmente el presupuesto real "ALCANTARILLADO LOTIFICACIÓN LAS PALMAS"
 * (las 9 partidas completas del documento del ingeniero).
 *
 * Cada ficha tiene un PRECIO DE VENTA EXACTO y limpio (L 20, 210, 110, 460,
 * 675, 1700, 4300, 19300, 2800) para que, al armar el proyecto con las
 * cantidades del documento, los totales cuadren al céntimo.
 *
 * Cómo se logra el precio exacto: utilidad 25% ⇒ costo_directo = precio/1.25,
 * y los items de cada ficha (rendimiento 1, sin desperdicio) suman ese
 * costo_directo. precio_venta = costo_directo × 1.25 = objetivo, sin
 * errores de redondeo.
 *
 * NO va a producción. Cargar en local:
 *   php artisan db:seed --class=Database\\Seeders\\PresupuestoLasPalmasSeeder
 *
 * Idempotente: si una ficha ya existe (por zona+nombre), no la duplica.
 *
 * @phpstan-type Componente array{0: string, 1: string, 2: CategoriaItem, 3: float}
 * @phpstan-type DefinicionFicha array{
 *     nombre: string,
 *     unidad: string,
 *     precio: string,
 *     cantidad_doc: string,
 *     componentes: list<array{0: string, 1: string, 2: CategoriaItem, 3: float}>,
 * }
 */
class PresupuestoLasPalmasSeeder extends Seeder
{
    private const string UTILIDAD = '25.00';

    public function run(): void
    {
        $zona = Zona::where('codigo', 'SRC')->first();

        if ($zona === null) {
            $this->command->warn('Zona SRC no existe. Ejecuta primero los seeders de catálogos.');

            return;
        }

        $this->crearUnidadesFaltantes();

        $service = new CalcularPrecioFichaService;
        $resumen = [];

        foreach ($this->definiciones() as $def) {
            $ficha = $this->crearFicha($zona, $def, $service);
            $resumen[] = [$ficha, $def];
        }

        $this->imprimirResumen($resumen);
    }

    /**
     * Las 9 fichas con su precio objetivo y la cantidad del documento real.
     * costo_directo de cada ficha = precio / 1.25 (utilidad 25%).
     *
     * @return list<array{
     *     nombre: string,
     *     unidad: string,
     *     precio: string,
     *     cantidad_doc: string,
     *     componentes: list<array{0: string, 1: string, 2: CategoriaItem, 3: float}>,
     * }>
     */
    private function definiciones(): array
    {
        return [
            [
                'nombre'       => 'MARCADO Y TRAZADO',
                'unidad'       => 'ML',
                'precio'       => '20.00',   // cd = 16.00
                'cantidad_doc' => '822.54',
                'componentes'  => [
                    ['CUADRILLA DE TOPOGRAFIA', 'JDR', CategoriaItem::ManoObra,   12.00],
                    ['ESTACAS Y PINTURA',       'UND', CategoriaItem::Materiales,  4.00],
                ],
            ],
            [
                'nombre'       => 'EXCAVACION',
                'unidad'       => 'M3',
                'precio'       => '210.00',  // cd = 168.00
                'cantidad_doc' => '1224.02',
                'componentes'  => [
                    ['PEON DE EXCAVACION', 'JDR', CategoriaItem::ManoObra,         100.00],
                    ['RETROEXCAVADORA',    'DIA', CategoriaItem::HerramientaEquipo, 68.00],
                ],
            ],
            [
                'nombre'       => 'RELLENO Y COMPACTADO',
                'unidad'       => 'M3',
                'precio'       => '110.00',  // cd = 88.00
                'cantidad_doc' => '616.90',
                'componentes'  => [
                    ['PEON DE RELLENO',         'JDR', CategoriaItem::ManoObra,         50.00],
                    ['COMPACTADORA VIBRATORIA', 'DIA', CategoriaItem::HerramientaEquipo, 38.00],
                ],
            ],
            [
                'nombre'       => 'SUM. E INST. DE TUBERIA PVC 6" RD-42',
                'unidad'       => 'ML',
                'precio'       => '460.00',  // cd = 368.00
                'cantidad_doc' => '822.54',
                'componentes'  => [
                    ['TUBERIA PVC 6" RD-42',  'ML',  CategoriaItem::Materiales, 320.00],
                    ['INSTALACION DE TUBERIA', 'JDR', CategoriaItem::ManoObra,   48.00],
                ],
            ],
            [
                'nombre'       => 'SUM. E INST. DE TUBERÍA PVC DE 8" RD-41',
                'unidad'       => 'ML',
                'precio'       => '675.00',  // cd = 540.00
                'cantidad_doc' => '395.65',
                'componentes'  => [
                    ['TUBERÍA PVC 8" RD-41',   'ML',  CategoriaItem::Materiales, 480.00],
                    ['INSTALACIÓN DE TUBERÍA 8"', 'JDR', CategoriaItem::ManoObra, 60.00],
                ],
            ],
            [
                'nombre'       => 'CONEXION DOMICILIARIA',
                'unidad'       => 'UND',
                'precio'       => '1700.00', // cd = 1360.00
                'cantidad_doc' => '169.00',
                'componentes'  => [
                    ['KIT DE CONEXION DOMICILIARIA', 'UND', CategoriaItem::Materiales, 1000.00],
                    ['MANO DE OBRA CONEXION',        'JDR', CategoriaItem::ManoObra,    360.00],
                ],
            ],
            [
                'nombre'       => 'CONSTRUCCIÓN DE CAJAS DE REGISTRO',
                'unidad'       => 'UND',
                'precio'       => '4300.00', // cd = 3440.00
                'cantidad_doc' => '169.00',
                'componentes'  => [
                    ['MATERIALES CAJA DE REGISTRO', 'UND', CategoriaItem::Materiales, 2600.00],
                    ['MANO DE OBRA CAJA DE REGISTRO', 'JDR', CategoriaItem::ManoObra,  840.00],
                ],
            ],
            [
                'nombre'       => 'SUMINISTRO Y ELABORACIÓN DE POZO',
                'unidad'       => 'UND',
                'precio'       => '19300.00', // cd = 15440.00
                'cantidad_doc' => '23.00',
                'componentes'  => [
                    ['MATERIALES POZO',     'UND', CategoriaItem::Materiales, 12000.00],
                    ['MANO DE OBRA POZO',   'JDR', CategoriaItem::ManoObra,    3440.00],
                ],
            ],
            [
                'nombre'       => 'CASQUETE Y TAPADERA',
                'unidad'       => 'UND',
                'precio'       => '2800.00', // cd = 2240.00
                'cantidad_doc' => '23.00',
                'componentes'  => [
                    ['CASQUETE Y TAPADERA HF', 'UND', CategoriaItem::Materiales, 1800.00],
                    ['INSTALACIÓN DE CASQUETE', 'JDR', CategoriaItem::ManoObra,   440.00],
                ],
            ],
        ];
    }

    /**
     * @param array{
     *     nombre: string,
     *     unidad: string,
     *     precio: string,
     *     cantidad_doc: string,
     *     componentes: list<array{0: string, 1: string, 2: CategoriaItem, 3: float}>,
     * } $def
     */
    private function crearFicha(Zona $zona, array $def, CalcularPrecioFichaService $service): Ficha
    {
        $unidades = UnidadMedida::pluck('id', 'codigo')->all();
        $unidadFicha = UnidadMedida::where('codigo', $def['unidad'])->firstOrFail();

        $ficha = Ficha::firstOrCreate(
            [
                'zona_id' => $zona->id,
                'nombre'  => $def['nombre'],
            ],
            [
                'unidad_medida_id'    => $unidadFicha->id,
                'utilidad_porcentaje' => self::UTILIDAD,
                'activa'              => true,
            ]
        );

        if ($ficha->lineas()->exists()) {
            $this->command->info("• Ficha ya existe: {$ficha->codigo} · {$def['nombre']}");

            return $ficha->fresh() ?? $ficha;
        }

        $orden = 0;

        foreach ($def['componentes'] as [$nombre, $unidadCodigo, $categoria, $precio]) {
            $item = Item::firstOrCreate(
                [
                    'zona_id'   => $zona->id,
                    'categoria' => $categoria->value,
                    'nombre'    => $nombre,
                ],
                [
                    'unidad_medida_id' => $unidades[$unidadCodigo] ?? $unidadFicha->id,
                    'precio_unitario'  => $precio,
                    'activo'           => true,
                ]
            );

            FichaLinea::create([
                'ficha_id'               => $ficha->id,
                'tipo'                   => TipoLineaFicha::Item,
                'orden'                  => $orden++,
                'item_id'                => $item->id,
                'rendimiento'            => '1.000000',
                'desperdicio_porcentaje' => '0.00',
            ]);
        }

        $resultado = $service->recalcularYPersistir($ficha->fresh());

        if ($resultado->precioVenta !== $def['precio']) {
            $this->command->error(
                "✗ {$def['nombre']}: esperado L {$def['precio']}, obtenido L {$resultado->precioVenta}."
            );
        }

        return $ficha->fresh() ?? $ficha;
    }

    /**
     * Unidades necesarias (idempotente).
     */
    private function crearUnidadesFaltantes(): void
    {
        $unidades = [
            ['codigo' => 'ML',  'nombre' => 'Metro lineal',  'simbolo' => 'ml'],
            ['codigo' => 'M3',  'nombre' => 'Metro cúbico',  'simbolo' => 'm³'],
            ['codigo' => 'UND', 'nombre' => 'Unidad',        'simbolo' => null],
            ['codigo' => 'JDR', 'nombre' => 'Jornada',       'simbolo' => null],
            ['codigo' => 'DIA', 'nombre' => 'Día (alquiler equipo)', 'simbolo' => null],
        ];

        foreach ($unidades as $datos) {
            UnidadMedida::firstOrCreate(
                ['codigo' => $datos['codigo']],
                ['nombre' => $datos['nombre'], 'simbolo' => $datos['simbolo'], 'activo' => true]
            );
        }
    }

    /**
     * Imprime la guía para armar el proyecto manualmente con las cantidades
     * del documento real, y el subtotal esperado.
     *
     * @param list<array{0: Ficha, 1: array{nombre: string, unidad: string, precio: string, cantidad_doc: string, componentes: list<array{0: string, 1: string, 2: CategoriaItem, 3: float}>}}> $resumen
     */
    private function imprimirResumen(array $resumen): void
    {
        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════════════════');
        $this->command->info('  '.count($resumen).' FICHAS APU LISTAS — PRESUPUESTO LAS PALMAS (zona SRC)');
        $this->command->info('  Armá el proyecto manualmente con estas cantidades:');
        $this->command->info('═══════════════════════════════════════════════════════════════');

        $subtotal = 0.0;

        foreach ($resumen as [$ficha, $def]) {
            $linea = (float) $def['cantidad_doc'] * (float) $def['precio'];
            $subtotal += $linea;

            $this->command->info(sprintf(
                '  %-38s %4s  %9s × L %-9s = L %s',
                $def['nombre'],
                $def['unidad'],
                $def['cantidad_doc'],
                $def['precio'],
                number_format($linea, 2)
            ));
        }

        $this->command->info('───────────────────────────────────────────────────────────────');
        $this->command->info('  SUBTOTAL (5 líneas): L '.number_format($subtotal, 2));
        $this->command->info('  + ISV 15% si aplica. Cliente sugerido: cualquiera de SRC.');
        $this->command->info('═══════════════════════════════════════════════════════════════');
    }
}
