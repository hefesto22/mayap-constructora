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
use Illuminate\Support\Collection;

/**
 * Seeder de demo con 28 fichas APU representativas de construcción
 * residencial/comercial en Honduras, en zona SRC con precios típicos
 * del mercado de Santa Rosa de Copán.
 *
 * Propósito: tener un dataset realista para ejercitar el listado con
 * tabs por zona, búsquedas, filtros, paginación, y la duplicación a
 * otra zona. NO se ejecuta automáticamente — solo manual:
 *
 *   php artisan db:seed --class=Database\\Seeders\\FichasConstructorasSeeder
 *
 * Idempotente: si una ficha o item ya existe (mismo nombre + zona),
 * no se duplica. Puedes correr el seeder múltiples veces sin efectos
 * colaterales.
 *
 * Cobertura por categoría de obra:
 *  - Preliminares (3): trazado, excavación, desalojo
 *  - Cimentación (3): zapata aislada, cimiento corrido, solera
 *  - Estructura (3): columna, viga, escalera (la losa ya está en demo)
 *  - Mampostería (4): bloque 6", bloque 8", repello int, repello ext
 *  - Cubiertas (2): estructura metálica, lámina aluzinc
 *  - Pisos (3): contrapiso, cerámica, porcelanato
 *  - Acabados (3): pintura int, pintura ext, cielo falso
 *  - Instalaciones (4): 2 eléctricas, hidráulica, sanitaria
 *  - Carpintería (2): puerta interior, ventana aluminio
 *
 * Total: 28 fichas (incluyendo la losa ya creada por FichaDemoSeeder)
 *
 * Modelo de captura: rendimiento EFECTIVO con 6 decimales (con la
 * pérdida ya considerada). Desperdicio se conserva como metadato.
 */
class FichasConstructorasSeeder extends Seeder
{
    public function run(): void
    {
        $zona = Zona::where('codigo', 'SRC')->first();

        if ($zona === null) {
            $this->command->warn('Zona SRC no existe. Ejecuta primero ZonaSeeder.');

            return;
        }

        $this->command->info('→ Asegurando unidades de medida extendidas...');
        $this->crearUnidadesExtendidas();

        $this->command->info('→ Asegurando items extendidos en zona SRC...');
        $this->crearItemsExtendidos($zona);

        $items = Item::where('zona_id', $zona->id)->get()->keyBy('nombre');
        $unidades = UnidadMedida::pluck('id', 'codigo')->all();
        $servicio = new CalcularPrecioFichaService;

        $this->command->info('→ Creando fichas APU constructoras...');
        $creadas = 0;
        $existentes = 0;

        foreach ($this->definicionesFichas() as $def) {
            if ($this->crearFicha($zona, $def, $items, $unidades, $servicio)) {
                $creadas++;
            } else {
                $existentes++;
            }
        }

        $this->command->info(
            "✓ FichasConstructorasSeeder: {$creadas} fichas creadas, ".
            "{$existentes} ya existían."
        );
    }

    /**
     * Unidades adicionales del oficio que las fichas constructoras
     * necesitan más allá de las del seeder demo.
     */
    private function crearUnidadesExtendidas(): void
    {
        $unidades = [
            ['codigo' => 'UNIDAD',  'nombre' => 'Unidad',                        'simbolo' => 'c/u'],
            ['codigo' => 'ML',      'nombre' => 'Metro lineal',                  'simbolo' => 'ml'],
            ['codigo' => 'TUBO',    'nombre' => 'Tubo (PVC, generalmente 6m)',   'simbolo' => null],
            ['codigo' => 'CUBETA',  'nombre' => 'Cubeta (5 galones)',            'simbolo' => null],
            ['codigo' => 'GAL',     'nombre' => 'Galón',                         'simbolo' => 'gal'],
            ['codigo' => 'LAMINA',  'nombre' => 'Lámina (tablayeso, etc.)',      'simbolo' => null],
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
     * Items adicionales para construcción residencial. Idempotente —
     * si el nombre ya existe en la zona, no se duplica.
     */
    private function crearItemsExtendidos(Zona $zona): void
    {
        $unidades = UnidadMedida::pluck('id', 'codigo')->all();

        // [nombre, codigoUnidad, categoria, precio]
        $catalogo = [
            // ─── Mampostería y bloque ────────────────────────────────
            ['BLOQUE DE 6"',                'UNIDAD', CategoriaItem::Materiales,        18.00],
            ['BLOQUE DE 8"',                'UNIDAD', CategoriaItem::Materiales,        22.00],
            ['PIEDRA DE CIMIENTO',          'M3',     CategoriaItem::Materiales,       350.00],

            // ─── Acero adicional ─────────────────────────────────────
            ['VAR#3',                       'LANCE',  CategoriaItem::Materiales,       180.00],
            ['VAR#5',                       'LANCE',  CategoriaItem::Materiales,       380.00],

            // ─── Hidráulica y sanitaria ──────────────────────────────
            ['TUBO PVC 1/2"',               'TUBO',   CategoriaItem::Materiales,        75.00],
            ['TUBO PVC 4"',                 'TUBO',   CategoriaItem::Materiales,       280.00],

            // ─── Eléctricos ──────────────────────────────────────────
            ['ALAMBRE THHN #12',            'ML',     CategoriaItem::Materiales,        12.00],
            ['TOMACORRIENTE DOBLE',         'UNIDAD', CategoriaItem::Materiales,        95.00],
            ['INTERRUPTOR SIMPLE',          'UNIDAD', CategoriaItem::Materiales,        65.00],
            ['LAMPARA LED 9W EMPOTRADA',    'UNIDAD', CategoriaItem::Materiales,       220.00],

            // ─── Acabados (pintura y otros) ──────────────────────────
            ['PINTURA LATEX',               'CUBETA', CategoriaItem::Materiales,       850.00],
            ['SELLADOR',                    'GAL',    CategoriaItem::Materiales,       280.00],
            ['THINNER',                     'GAL',    CategoriaItem::Materiales,       130.00],

            // ─── Pisos ───────────────────────────────────────────────
            ['CERAMICA NACIONAL 30X30',     'M2',     CategoriaItem::Materiales,       180.00],
            ['PORCELANATO 60X60',           'M2',     CategoriaItem::Materiales,       450.00],

            // ─── Cielo falso y estructura ────────────────────────────
            ['LAMINA TABLAYESO 1/2"',       'LAMINA', CategoriaItem::Materiales,       220.00],
            ['PERFIL METALICO 2X4',         'LANCE',  CategoriaItem::Materiales,       220.00],

            // ─── Carpintería y aluminio ──────────────────────────────
            ['PUERTA MADERA Y MARCO',       'UNIDAD', CategoriaItem::Materiales,      2500.00],
            ['VENTANA ALUMINIO 1.20X1.00',  'UNIDAD', CategoriaItem::Materiales,      3200.00],

            // ─── Aparatos sanitarios (para futuras fichas) ──────────
            ['INODORO ESTANDAR',            'UNIDAD', CategoriaItem::Materiales,      2800.00],
            ['LAVAMANOS DE PEDESTAL',       'UNIDAD', CategoriaItem::Materiales,      1500.00],
        ];

        foreach ($catalogo as [$nombre, $codigoUnidad, $categoria, $precio]) {
            if (! isset($unidades[$codigoUnidad])) {
                $this->command->warn("Unidad {$codigoUnidad} no existe — saltando '{$nombre}'.");

                continue;
            }

            Item::firstOrCreate(
                [
                    'zona_id'   => $zona->id,
                    'categoria' => $categoria->value,
                    'nombre'    => $nombre,
                ],
                [
                    'unidad_medida_id' => $unidades[$codigoUnidad],
                    'precio_unitario'  => $precio,
                    'activo'           => true,
                ]
            );
        }
    }

    /**
     * Crea una ficha completa con sus líneas. Devuelve true si fue
     * creada, false si ya existía.
     *
     * @param array<string, mixed> $def
     * @param Collection<string, Item> $items
     * @param array<string, int> $unidades
     */
    private function crearFicha(
        Zona $zona,
        array $def,
        Collection $items,
        array $unidades,
        CalcularPrecioFichaService $servicio,
    ): bool {
        if (! isset($unidades[$def['unidad']])) {
            $this->command->warn("Unidad {$def['unidad']} no existe — saltando ficha {$def['nombre']}.");

            return false;
        }

        $existente = Ficha::where('zona_id', $zona->id)
            ->where('nombre', $def['nombre'])
            ->first();

        if ($existente !== null) {
            return false;
        }

        $ficha = Ficha::create([
            'zona_id'             => $zona->id,
            'unidad_medida_id'    => $unidades[$def['unidad']],
            'nombre'              => $def['nombre'],
            'descripcion'         => $def['descripcion'] ?? null,
            'parametros_tecnicos' => $def['parametros'] ?? [],
            'utilidad_porcentaje' => $def['utilidad'] ?? 25.00,
            'activa'              => true,
        ]);

        $orden = 0;

        foreach ($def['lineas_item'] ?? [] as [$nombreItem, $rend, $desp]) {
            if (! $items->has($nombreItem)) {
                $this->command->warn(
                    "Item '{$nombreItem}' no existe en zona {$zona->codigo} — ".
                    "saltando línea de ficha '{$def['nombre']}'."
                );

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

        foreach ($def['lineas_porcentaje'] ?? [] as [$descripcion, $porcentaje, $base, $destino]) {
            FichaLinea::create([
                'ficha_id'          => $ficha->id,
                'tipo'              => TipoLineaFicha::Porcentaje,
                'orden'             => $orden++,
                'descripcion'       => $descripcion,
                'porcentaje'        => $porcentaje,
                'categoria_base'    => $base,
                'categoria_destino' => $destino,
            ]);
        }

        $servicio->recalcularYPersistir($ficha->fresh());

        return true;
    }

    /**
     * Catálogo declarativo de las 28 fichas. Cada definición describe
     * la obra unitaria, sus líneas tipo item con rendimiento efectivo
     * (con desperdicio incluido a 6 decimales) y opcionalmente líneas
     * tipo porcentaje (HERRAMIENTA MENOR 5% sobre MO en la mayoría).
     *
     * Los rendimientos están calibrados con base en metodología
     * estándar del oficio (dosificaciones de concreto 1:2:3 para
     * 3000 PSI, jornadas típicas de mano de obra en HN, etc.).
     *
     * @return array<int, array<string, mixed>>
     */
    private function definicionesFichas(): array
    {
        $herramienta = ['HERRAMIENTA MENOR', '5.00', CategoriaBaseLinea::ManoObra->value, CategoriaItem::HerramientaEquipo->value];

        return [
            // ═══════════════════════════════════════════════════════════
            // PRELIMINARES
            // ═══════════════════════════════════════════════════════════
            [
                'nombre'      => 'TRAZADO Y NIVELACIÓN',
                'unidad'      => 'M2',
                'utilidad'    => 25.00,
                'parametros'  => ['ÁREA DE REFERENCIA' => '1 M² de planta a trazar'],
                'lineas_item' => [
                    ['ALAMBRE DE AMARRE', '0.052500', '5.00'],
                    ['CLAVOS',            '0.021000', '5.00'],
                    ['ALBAÑIL',           '0.020000', '0.00'],
                    ['AYUDANTE',          '0.020000', '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'      => 'EXCAVACIÓN A MANO EN MATERIAL TIPO II',
                'unidad'      => 'M3',
                'utilidad'    => 25.00,
                'parametros'  => ['MATERIAL' => 'Tipo II — tierra suelta a semicompacta'],
                'lineas_item' => [
                    ['AYUDANTE', '0.500000', '0.00'],
                ],
                'lineas_porcentaje' => [
                    ['HERRAMIENTA MENOR', '8.00', CategoriaBaseLinea::ManoObra->value, CategoriaItem::HerramientaEquipo->value],
                ],
            ],
            [
                'nombre'      => 'DESALOJO DE MATERIAL EXCAVADO',
                'unidad'      => 'M3',
                'utilidad'    => 25.00,
                'parametros'  => ['DISTANCIA REFERENCIAL' => 'Hasta 30m del sitio'],
                'lineas_item' => [
                    ['AYUDANTE', '0.300000', '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],

            // ═══════════════════════════════════════════════════════════
            // CIMENTACIÓN
            // ═══════════════════════════════════════════════════════════
            [
                'nombre'     => 'ZAPATA AISLADA 1.00X1.00X0.30 (3000 PSI)',
                'unidad'     => 'UNIDAD',
                'utilidad'   => 25.00,
                'parametros' => [
                    'DIMENSIONES'         => '1.00 × 1.00 × 0.30 m',
                    'VOLUMEN DE CONCRETO' => '0.30 M³',
                    'RESISTENCIA'         => '3000 PSI',
                    'REFUERZO'            => 'VAR#4 @20cm A.S.',
                ],
                'lineas_item' => [
                    ['CEMENTO',           '2.677500',  '5.00'],
                    ['ARENA',              '0.171600', '10.00'],
                    ['GRAVA TRIT 3/4',    '0.257400', '10.00'],
                    ['AGUA',              '0.075000', '25.00'],
                    ['VAR#4',             '4.725000',  '5.00'],
                    ['ALAMBRE DE AMARRE', '1.575000',  '5.00'],
                    ['ALBAÑIL',           '0.500000',  '0.00'],
                    ['AYUDANTE',          '0.500000',  '0.00'],
                    ['CONCRETERA',        '0.050000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'CIMIENTO CORRIDO DE PIEDRA',
                'unidad'     => 'ML',
                'utilidad'   => 25.00,
                'parametros' => [
                    'SECCIÓN'     => '0.40 × 0.50 m',
                    'AGLUTINANTE' => 'Mortero de cemento 1:4',
                ],
                'lineas_item' => [
                    ['PIEDRA DE CIMIENTO', '0.472500', '5.00'],
                    ['CEMENTO',            '1.575000', '5.00'],
                    ['ARENA',              '0.198000', '10.00'],
                    ['AGUA',               '0.062500', '25.00'],
                    ['ALBAÑIL',            '0.400000', '0.00'],
                    ['AYUDANTE',           '0.400000', '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'SOLERA DE FUNDACIÓN 0.20X0.30 (3000 PSI)',
                'unidad'     => 'ML',
                'utilidad'   => 25.00,
                'parametros' => [
                    'SECCIÓN'             => '0.20 × 0.30 m',
                    'VOLUMEN DE CONCRETO' => '0.06 M³/M',
                    'REFUERZO LONG.'      => '4 VAR#4',
                    'ESTRIBOS'            => 'VAR#3 @ 0.20m',
                ],
                'lineas_item' => [
                    ['CEMENTO',           '0.535500',  '5.00'],
                    ['ARENA',             '0.034320', '10.00'],
                    ['GRAVA TRIT 3/4',    '0.051480', '10.00'],
                    ['AGUA',              '0.015000', '25.00'],
                    ['VAR#4',             '0.735000',  '5.00'],
                    ['VAR#3',             '0.735000',  '5.00'],
                    ['ALAMBRE DE AMARRE', '0.105000',  '5.00'],
                    ['ALBAÑIL',           '0.200000',  '0.00'],
                    ['AYUDANTE',          '0.200000',  '0.00'],
                    ['CONCRETERA',        '0.012000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],

            // ═══════════════════════════════════════════════════════════
            // ESTRUCTURA
            // ═══════════════════════════════════════════════════════════
            [
                'nombre'     => 'COLUMNA DE CONCRETO 0.30X0.30 (3000 PSI VAR#4)',
                'unidad'     => 'ML',
                'utilidad'   => 25.00,
                'parametros' => [
                    'SECCIÓN'             => '0.30 × 0.30 m',
                    'VOLUMEN DE CONCRETO' => '0.09 M³/M',
                    'REFUERZO LONG.'      => '4 VAR#4',
                    'ESTRIBOS'            => 'VAR#3 @ 0.15m',
                ],
                'lineas_item' => [
                    ['CEMENTO',           '0.803250',  '5.00'],
                    ['ARENA',             '0.051480', '10.00'],
                    ['GRAVA TRIT 3/4',    '0.077220', '10.00'],
                    ['AGUA',              '0.022500', '25.00'],
                    ['VAR#4',             '0.700000',  '5.00'],
                    ['VAR#3',             '0.525000',  '5.00'],
                    ['ALAMBRE DE AMARRE', '0.157500',  '5.00'],
                    ['CLAVOS',            '0.315000',  '5.00'],
                    ['ALBAÑIL',           '0.300000',  '0.00'],
                    ['AYUDANTE',          '0.300000',  '0.00'],
                    ['CONCRETERA',        '0.015000',  '0.00'],
                    ['VIBRADOR',          '0.015000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'VIGA DE CONCRETO 0.20X0.30 (3000 PSI VAR#4)',
                'unidad'     => 'ML',
                'utilidad'   => 25.00,
                'parametros' => [
                    'SECCIÓN'             => '0.20 × 0.30 m',
                    'VOLUMEN DE CONCRETO' => '0.06 M³/M',
                    'REFUERZO LONG.'      => '4 VAR#4',
                    'ESTRIBOS'            => 'VAR#3 @ 0.15m',
                ],
                'lineas_item' => [
                    ['CEMENTO',           '0.535500',  '5.00'],
                    ['ARENA',             '0.034320', '10.00'],
                    ['GRAVA TRIT 3/4',    '0.051480', '10.00'],
                    ['AGUA',              '0.015000', '25.00'],
                    ['VAR#4',             '0.735000',  '5.00'],
                    ['VAR#3',             '0.525000',  '5.00'],
                    ['ALAMBRE DE AMARRE', '0.105000',  '5.00'],
                    ['CLAVOS',            '0.262500',  '5.00'],
                    ['ALBAÑIL',           '0.250000',  '0.00'],
                    ['AYUDANTE',          '0.250000',  '0.00'],
                    ['CONCRETERA',        '0.012000',  '0.00'],
                    ['VIBRADOR',          '0.012000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'ESCALERA DE CONCRETO REFORZADO',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'VOLUMEN PROMEDIO' => '0.15 M³/M² (huella + contrahuella)',
                    'RESISTENCIA'      => '3000 PSI',
                ],
                'lineas_item' => [
                    ['CEMENTO',           '1.338750',  '5.00'],
                    ['ARENA',             '0.085800', '10.00'],
                    ['GRAVA TRIT 3/4',    '0.128700', '10.00'],
                    ['AGUA',              '0.037500', '25.00'],
                    ['VAR#4',             '1.575000',  '5.00'],
                    ['VAR#3',             '0.840000',  '5.00'],
                    ['ALAMBRE DE AMARRE', '0.420000',  '5.00'],
                    ['LAMINA DE ALUZINC', '2.625000',  '5.00'],
                    ['CLAVOS',            '0.315000',  '5.00'],
                    ['ALBAÑIL',           '0.800000',  '0.00'],
                    ['AYUDANTE',          '0.800000',  '0.00'],
                    ['CONCRETERA',        '0.020000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],

            // ═══════════════════════════════════════════════════════════
            // MAMPOSTERÍA
            // ═══════════════════════════════════════════════════════════
            [
                'nombre'     => 'PARED DE BLOQUE DE 6"',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'BLOQUES POR M²' => '12.5 (bloque 0.20×0.40)',
                    'JUNTA'          => '1.5 cm con mortero 1:4',
                ],
                'lineas_item' => [
                    ['BLOQUE DE 6"',      '13.125000', '5.00'],
                    ['CEMENTO',           '0.210000',  '5.00'],
                    ['ARENA',             '0.033000', '10.00'],
                    ['AGUA',              '0.012500', '25.00'],
                    ['VAR#3',             '0.157500',  '5.00'],
                    ['ALAMBRE DE AMARRE', '0.052500',  '5.00'],
                    ['ALBAÑIL',           '0.200000',  '0.00'],
                    ['AYUDANTE',          '0.200000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'PARED DE BLOQUE DE 8"',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'BLOQUES POR M²' => '12.5 (bloque 0.20×0.40)',
                    'USO'            => 'Muros de carga / cerramiento exterior',
                ],
                'lineas_item' => [
                    ['BLOQUE DE 8"',      '13.125000', '5.00'],
                    ['CEMENTO',           '0.315000',  '5.00'],
                    ['ARENA',             '0.049500', '10.00'],
                    ['AGUA',              '0.018750', '25.00'],
                    ['VAR#3',             '0.210000',  '5.00'],
                    ['ALAMBRE DE AMARRE', '0.073500',  '5.00'],
                    ['ALBAÑIL',           '0.220000',  '0.00'],
                    ['AYUDANTE',          '0.220000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'REPELLO Y AFINADO INTERIOR',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'ESPESOR' => '1.5 cm',
                    'MORTERO' => '1:4 cemento-arena',
                ],
                'lineas_item' => [
                    ['CEMENTO',  '0.133875',  '5.00'],
                    ['ARENA',    '0.008580', '10.00'],
                    ['AGUA',     '0.003750', '25.00'],
                    ['ALBAÑIL',  '0.180000',  '0.00'],
                    ['AYUDANTE', '0.180000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'REPELLO Y AFINADO EXTERIOR',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'ESPESOR' => '2.0 cm',
                    'MORTERO' => '1:4 cemento-arena (más resistente al clima)',
                ],
                'lineas_item' => [
                    ['CEMENTO',  '0.178500',  '5.00'],
                    ['ARENA',    '0.011440', '10.00'],
                    ['AGUA',     '0.005000', '25.00'],
                    ['ALBAÑIL',  '0.200000',  '0.00'],
                    ['AYUDANTE', '0.200000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],

            // ═══════════════════════════════════════════════════════════
            // CUBIERTAS
            // ═══════════════════════════════════════════════════════════
            [
                'nombre'     => 'ESTRUCTURA DE TECHO METÁLICA',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'TIPO' => 'Perfiles 2×4 con soldadura',
                    'PASO' => 'Clavadores @ 0.90m',
                ],
                'lineas_item' => [
                    ['PERFIL METALICO 2X4', '0.157500',  '5.00'],
                    ['TORNILLOS',           '0.157500',  '5.00'],
                    ['SOLDADOR',            '0.100000',  '0.00'],
                    ['AYUDANTE',            '0.100000',  '0.00'],
                    ['SOLDADORA',           '0.100000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'LÁMINA DE ALUZINC CALIBRE 26',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'CALIBRE'   => '26',
                    'TRASLAPES' => 'Mínimo 15cm en ambos sentidos',
                ],
                'lineas_item' => [
                    ['LAMINA DE ALUZINC', '11.298000', '5.00'],
                    ['TORNILLOS',         '0.210000',  '5.00'],
                    ['ALBAÑIL',           '0.050000',  '0.00'],
                    ['AYUDANTE',          '0.050000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],

            // ═══════════════════════════════════════════════════════════
            // PISOS
            // ═══════════════════════════════════════════════════════════
            [
                'nombre'     => 'CONTRAPISO DE CONCRETO 2500 PSI E=7CM',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'ESPESOR'             => '7 cm',
                    'VOLUMEN DE CONCRETO' => '0.07 M³/M²',
                    'RESISTENCIA'         => '2500 PSI',
                ],
                'lineas_item' => [
                    ['CEMENTO',        '0.514500',  '5.00'],
                    ['ARENA',          '0.042350', '10.00'],
                    ['GRAVA TRIT 3/4', '0.061600', '10.00'],
                    ['AGUA',           '0.017500', '25.00'],
                    ['ALBAÑIL',        '0.150000',  '0.00'],
                    ['AYUDANTE',       '0.150000',  '0.00'],
                    ['CONCRETERA',     '0.012000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'PISO CERÁMICA NACIONAL 30X30',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'FORMATO' => '30 × 30 cm',
                    'PEGADO'  => 'Mortero 1:3',
                ],
                'lineas_item' => [
                    ['CERAMICA NACIONAL 30X30', '1.050000',  '5.00'],
                    ['CEMENTO',                 '0.052500',  '5.00'],
                    ['ARENA',                   '0.005500', '10.00'],
                    ['ALBAÑIL',                 '0.200000',  '0.00'],
                    ['AYUDANTE',                '0.200000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'PISO PORCELANATO 60X60',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'FORMATO' => '60 × 60 cm',
                    'PEGADO'  => 'Pegamento de pared y piso (porcelanato)',
                ],
                'lineas_item' => [
                    ['PORCELANATO 60X60', '1.070000',  '7.00'],
                    ['CEMENTO',           '0.105000',  '5.00'],
                    ['ARENA',             '0.006600', '10.00'],
                    ['ALBAÑIL',           '0.250000',  '0.00'],
                    ['AYUDANTE',          '0.250000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],

            // ═══════════════════════════════════════════════════════════
            // ACABADOS
            // ═══════════════════════════════════════════════════════════
            [
                'nombre'     => 'PINTURA LÁTEX INTERIOR 2 MANOS',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'MANOS'       => '2',
                    'RENDIMIENTO' => '~75 M²/cubeta',
                ],
                'lineas_item' => [
                    ['PINTURA LATEX', '0.014000',  '5.00'],
                    ['SELLADOR',      '0.010500',  '5.00'],
                    ['ALBAÑIL',       '0.080000',  '0.00'],
                    ['AYUDANTE',      '0.040000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'PINTURA LÁTEX EXTERIOR 2 MANOS',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'MANOS'       => '2 + sellador previo',
                    'RENDIMIENTO' => '~60 M²/cubeta',
                ],
                'lineas_item' => [
                    ['PINTURA LATEX', '0.017500',  '5.00'],
                    ['SELLADOR',      '0.013100',  '5.00'],
                    ['ALBAÑIL',       '0.090000',  '0.00'],
                    ['AYUDANTE',      '0.050000',  '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'CIELO FALSO DE TABLAYESO',
                'unidad'     => 'M2',
                'utilidad'   => 25.00,
                'parametros' => [
                    'ESTRUCTURA' => 'Perfiles metálicos @ 0.60m',
                    'LÁMINA'     => 'Tablayeso 1/2"',
                ],
                'lineas_item' => [
                    ['LAMINA TABLAYESO 1/2"', '0.367500', '5.00'],
                    ['PERFIL METALICO 2X4',   '0.315000', '5.00'],
                    ['TORNILLOS',             '0.052500', '5.00'],
                    ['ALBAÑIL',               '0.180000', '0.00'],
                    ['AYUDANTE',              '0.180000', '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],

            // ═══════════════════════════════════════════════════════════
            // INSTALACIONES
            // ═══════════════════════════════════════════════════════════
            [
                'nombre'     => 'PUNTO ELÉCTRICO DE ILUMINACIÓN',
                'unidad'     => 'UNIDAD',
                'utilidad'   => 25.00,
                'parametros' => [
                    'INCLUYE' => 'Tubería conduit + alambrado + interruptor + lámpara',
                ],
                'lineas_item' => [
                    ['ALAMBRE THHN #12',         '8.400000', '5.00'],
                    ['LAMPARA LED 9W EMPOTRADA', '1.000000', '0.00'],
                    ['INTERRUPTOR SIMPLE',       '1.000000', '0.00'],
                    ['ALBAÑIL',                  '0.250000', '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'PUNTO ELÉCTRICO DE TOMACORRIENTE',
                'unidad'     => 'UNIDAD',
                'utilidad'   => 25.00,
                'parametros' => [
                    'INCLUYE' => 'Tubería conduit + alambrado + tomacorriente doble',
                ],
                'lineas_item' => [
                    ['ALAMBRE THHN #12',    '6.300000', '5.00'],
                    ['TOMACORRIENTE DOBLE', '1.000000', '0.00'],
                    ['ALBAÑIL',             '0.200000', '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'SALIDA HIDRÁULICA AGUA FRÍA',
                'unidad'     => 'UNIDAD',
                'utilidad'   => 25.00,
                'parametros' => [
                    'TUBERÍA' => 'PVC 1/2" presión',
                    'INCLUYE' => 'Conexiones, codos, válvula de paso',
                ],
                'lineas_item' => [
                    ['TUBO PVC 1/2"', '1.575000', '5.00'],
                    ['ALBAÑIL',       '0.300000', '0.00'],
                    ['AYUDANTE',      '0.200000', '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'SALIDA SANITARIA 4"',
                'unidad'     => 'UNIDAD',
                'utilidad'   => 25.00,
                'parametros' => [
                    'TUBERÍA'   => 'PVC 4" sanitario',
                    'PENDIENTE' => 'Mínimo 2%',
                ],
                'lineas_item' => [
                    ['TUBO PVC 4"', '1.050000', '5.00'],
                    ['ALBAÑIL',     '0.400000', '0.00'],
                    ['AYUDANTE',    '0.300000', '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],

            // ═══════════════════════════════════════════════════════════
            // CARPINTERÍA Y ALUMINIO
            // ═══════════════════════════════════════════════════════════
            [
                'nombre'     => 'PUERTA INTERIOR DE MADERA + MARCO',
                'unidad'     => 'UNIDAD',
                'utilidad'   => 25.00,
                'parametros' => [
                    'DIMENSIONES' => '0.80 × 2.10 m',
                    'INCLUYE'     => 'Marco + bisagras + chapa + pintura',
                ],
                'lineas_item' => [
                    ['PUERTA MADERA Y MARCO', '1.000000', '0.00'],
                    ['TORNILLOS',             '0.210000', '5.00'],
                    ['ALBAÑIL',               '0.500000', '0.00'],
                    ['AYUDANTE',              '0.200000', '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
            [
                'nombre'     => 'VENTANA ALUMINIO + VIDRIO 1.20X1.00',
                'unidad'     => 'UNIDAD',
                'utilidad'   => 25.00,
                'parametros' => [
                    'DIMENSIONES' => '1.20 × 1.00 m',
                    'TIPO'        => 'Corrediza con malla',
                ],
                'lineas_item' => [
                    ['VENTANA ALUMINIO 1.20X1.00', '1.000000', '0.00'],
                    ['TORNILLOS',                  '0.315000', '5.00'],
                    ['SOLDADOR',                   '0.400000', '0.00'],
                    ['AYUDANTE',                   '0.300000', '0.00'],
                ],
                'lineas_porcentaje' => [$herramienta],
            ],
        ];
    }
}
