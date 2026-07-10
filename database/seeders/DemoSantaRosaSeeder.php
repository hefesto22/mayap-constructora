<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CategoriaItem;
use App\Enums\EstadoMaquina;
use App\Enums\EstadoProyecto;
use App\Enums\TipoLineaFicha;
use App\Enums\TipoMaquina;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Ficha;
use App\Models\FichaLinea;
use App\Models\Item;
use App\Models\Maquina;
use App\Models\Material;
use App\Models\Proyecto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Models\Zona;
use App\Services\Fichas\CalcularPrecioFichaService;
use App\Services\Proyectos\AgregarRenglonAProyectoService;
use App\Support\Roles;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * DEMO SANTA ROSA — puebla el sistema con datos REALES de Santa Rosa de
 * Copán para probar casos reales de punta a punta:
 *
 *  - SOLO la zona SRC y SOLO la bodega SANTA ROSA.
 *  - Usuarios por rol (contraseña 12345678 para todos).
 *  - Base de precios real (materiales, mano de obra, equipo) en HNL.
 *  - Parque de maquinaria real con tarifas por hora.
 *  - 3 proyectos con fichas APU reales: CASA, ALCANTARILLADO y EDIFICIO.
 *
 * Requiere que los seeders base ya corrieron (Shield + RolesInventario).
 * Idempotente: firstOrCreate en todo — re-correrlo no duplica nada.
 *
 * NO va a producción.
 */
class DemoSantaRosaSeeder extends Seeder
{
    private Zona $zona;

    private Bodega $bodega;

    public function run(): void
    {
        if (Role::where('name', Roles::BODEGUERO)->doesntExist()) {
            $this->command?->error('Faltan los roles base. Corre primero: php artisan db:seed');

            return;
        }

        $this->zona = Zona::firstOrCreate(
            ['codigo' => 'SRC'],
            ['nombre' => 'SANTA ROSA DE COPAN', 'activa' => true],
        );

        $this->crearUnidades();
        $this->bodega = Bodega::firstOrCreate(
            ['nombre' => 'BODEGA SANTA ROSA'],
            ['activo' => true],
        );

        $usuarios = $this->crearUsuarios();
        $this->crearBaseDePrecios();
        $this->crearMaquinaria();

        // Fichas de alcantarillado: reusa el presupuesto real Las Palmas.
        $this->call(PresupuestoLasPalmasSeeder::class);

        $casa = $this->crearProyectoCasa();
        $alcantarillado = $this->crearProyectoAlcantarillado();
        $edificio = $this->crearProyectoEdificio();

        // Obras vivas con su encargado de campo.
        foreach ([$casa, $alcantarillado] as $obra) {
            $obra->encargados()->syncWithoutDetaching([$usuarios['obra']->id]);
        }

        // Items de material sin ficha de inventario → crear/vincular Material.
        $this->call(VincularMaterialesFaltantesSeeder::class);

        $this->command?->info('');
        $this->command?->info('══════════════════════════════════════════════════════');
        $this->command?->info('  DEMO SANTA ROSA LISTA');
        $this->command?->info('  Zona SRC · BODEGA SANTA ROSA · 6 usuarios (pass 12345678)');
        $this->command?->info('  CASA en ejecución · ALCANTARILLADO en ejecución · EDIFICIO aprobado');
        $this->command?->info('══════════════════════════════════════════════════════');
    }

    // ─── Usuarios por rol ────────────────────────────────────────────

    /**
     * @return array<string, User>
     */
    private function crearUsuarios(): array
    {
        $definiciones = [
            'admin'      => ['ADMINISTRADOR MAYAP', 'admin@gmail.com', Utils::getSuperAdminName()],
            'gerente'    => ['GERENTE GENERAL', 'gerente@gmail.com', Roles::GERENCIA],
            'recepcion'  => ['RECEPCION Y COMPRAS', 'rrecepcion@gmail.com', Roles::RECEPCION],
            'bodeguero'  => ['BODEGUERO SANTA ROSA', 'bodeguero@gmail.com', Roles::BODEGUERO],
            'obra'       => ['ENCARGADO DE OBRA', 'obra@gmail.com', Roles::ENCARGADO_OBRA],
            'maquinaria' => ['JEFE DE MAQUINARIA', 'maquinaria@gmail.com', Roles::MAQUINARIA],
        ];

        $usuarios = [];

        foreach ($definiciones as $clave => [$nombre, $email, $rol]) {
            $usuario = User::firstOrCreate(
                ['email' => $email],
                ['name' => $nombre, 'password' => Hash::make('12345678'), 'is_active' => true],
            );

            if (! $usuario->hasRole($rol)) {
                $usuario->assignRole($rol);
            }

            $usuarios[$clave] = $usuario;
        }

        // Quienes operan la bodega física la llevan asignada (alcance).
        foreach (['bodeguero', 'recepcion'] as $clave) {
            $usuarios[$clave]->bodegas()->syncWithoutDetaching([$this->bodega->id]);
        }

        return $usuarios;
    }

    // ─── Base de precios (Santa Rosa, HNL, precios de plaza) ────────

    private function crearBaseDePrecios(): void
    {
        // MATERIALES: [nombre, unidad, precio] — crean Material + Item ligados.
        $materiales = [
            ['CEMENTO GRIS 42.5 KG',        'BLS', '215.00'],
            ['ARENA DE RIO',                'M3',  '450.00'],
            ['GRAVA TRITURADA 3/4',         'M3',  '520.00'],
            ['BLOQUE DE CONCRETO 15X20X40', 'UND', '18.00'],
            ['HIERRO CORRUGADO 3/8 X 6M',   'VAR', '62.00'],
            ['HIERRO CORRUGADO 1/2 X 6M',   'VAR', '115.00'],
            ['ALAMBRE DE AMARRE',           'LB',  '28.00'],
            ['LAMINA ALUZINC CAL.26',       'M2',  '160.00'],
            ['PERLIN CHAPA 14 DE 4X2',      'ML',  '95.00'],
            ['CERAMICA DE PISO 45X45',      'M2',  '210.00'],
            ['PEGAMENTO BONDEX GRIS',       'BLS', '180.00'],
            ['VIGUETA PRETENSADA',          'ML',  '85.00'],
            ['BOVEDILLA DE CONCRETO',       'UND', '22.00'],
            ['VENTANA DE ALUMINIO Y VIDRIO', 'M2', '1850.00'],
            ['TORNILLERIA PARA TECHO',      'UND', '12.00'],
            ['MADERA DE PINO PARA ENCOFRADO', 'PT', '18.00'],
        ];

        foreach ($materiales as [$nombre, $unidad, $precio]) {
            $material = Material::query()->firstWhere('nombre', $nombre)
                ?? Material::factory()->create(['nombre' => $nombre]);

            $item = $this->item($nombre, $unidad, CategoriaItem::Materiales, $precio);

            if ($item->material_id === null) {
                $item->update(['material_id' => $material->id]);
            }
        }

        // MANO DE OBRA (jornales de plaza en SRC).
        foreach ([
            ['ALBAÑIL',                'JDR', '600.00'],
            ['PEON DE CONSTRUCCION',   'JDR', '420.00'],
            ['ARMADOR DE HIERRO',      'JDR', '650.00'],
            ['SOLDADOR',               'JDR', '700.00'],
            ['INSTALADOR DE VENTANAS', 'JDR', '620.00'],
        ] as [$nombre, $unidad, $precio]) {
            $this->item($nombre, $unidad, CategoriaItem::ManoObra, $precio);
        }

        // HERRAMIENTA Y EQUIPO (hora máquina — el costo APU; el parque real
        // vive en Maquinaria).
        foreach ([
            ['HORA EXCAVADORA CAT 320',   'HRA', '1400.00'],
            ['HORA RETROEXCAVADORA',      'HRA', '950.00'],
            ['HORA MEZCLADORA 2 BOLSAS',  'HRA', '180.00'],
            ['ANDAMIOS Y HERRAMIENTA MENOR', 'DIA', '250.00'],
        ] as [$nombre, $unidad, $precio]) {
            $this->item($nombre, $unidad, CategoriaItem::HerramientaEquipo, $precio);
        }
    }

    // ─── Maquinaria real ─────────────────────────────────────────────

    private function crearMaquinaria(): void
    {
        $maquinas = [
            ['EXCAVADORA CAT 320D',        TipoMaquina::Excavadora,      'CATERPILLAR', '320D',   2019, '1400.00', '5240.00'],
            ['RETROEXCAVADORA JD 310SL',   TipoMaquina::Retroexcavadora, 'JOHN DEERE',  '310SL',  2021, '950.00',  '3120.00'],
            ['VIBROCOMPACTADORA WACKER',   TipoMaquina::Compactadora,    'WACKER',      'RD12A',  2022, '450.00',  '890.00'],
            ['VOLQUETA MACK 12M3',         TipoMaquina::Volqueta,        'MACK',        'GU813',  2018, '800.00',  '8450.00'],
            ['MEZCLADORA DE 2 BOLSAS',     TipoMaquina::Otro,            'CIPSA',       'MAXI-14', 2023, '180.00',  '640.00'],
        ];

        foreach ($maquinas as [$nombre, $tipo, $marca, $modelo, $anio, $tarifa, $horometro]) {
            Maquina::firstOrCreate(
                ['nombre' => $nombre],
                [
                    'tipo'             => $tipo->value,
                    'marca'            => $marca,
                    'modelo'           => $modelo,
                    'anio'             => $anio,
                    'horometro_actual' => $horometro,
                    'tarifa_hora'      => $tarifa,
                    'jornada_horas'    => '8.00',
                    'estado'           => EstadoMaquina::Disponible->value,
                    'activo'           => true,
                ],
            );
        }
    }

    // ─── Proyecto 1: CASA (en ejecución) ─────────────────────────────

    private function crearProyectoCasa(): Proyecto
    {
        $fichas = [
            // [nombre, unidad, componentes[[item, cantidad(rendimiento)]], cantidad del proyecto, capítulo]
            ['ZAPATA CORRIDA 40X20 CM', 'ML', [
                ['CEMENTO GRIS 42.5 KG', 0.35], ['ARENA DE RIO', 0.04], ['GRAVA TRITURADA 3/4', 0.05],
                ['HIERRO CORRUGADO 3/8 X 6M', 2.20], ['ALAMBRE DE AMARRE', 0.25],
                ['ALBAÑIL', 0.12], ['PEON DE CONSTRUCCION', 0.20],
            ], '46.00', '01 CIMENTACION'],
            ['PARED DE BLOQUE 15 REFORZADA', 'M2', [
                ['BLOQUE DE CONCRETO 15X20X40', 12.50], ['CEMENTO GRIS 42.5 KG', 0.10],
                ['ARENA DE RIO', 0.012], ['HIERRO CORRUGADO 3/8 X 6M', 0.40],
                ['ALBAÑIL', 0.20], ['PEON DE CONSTRUCCION', 0.10],
            ], '168.00', '02 PAREDES'],
            ['REPELLO Y PULIDO DE PAREDES', 'M2', [
                ['CEMENTO GRIS 42.5 KG', 0.12], ['ARENA DE RIO', 0.015],
                ['ALBAÑIL', 0.15], ['PEON DE CONSTRUCCION', 0.08],
            ], '336.00', '02 PAREDES'],
            ['TECHO METALICO CON LAMINA ALUZINC', 'M2', [
                ['LAMINA ALUZINC CAL.26', 1.05], ['PERLIN CHAPA 14 DE 4X2', 1.80],
                ['TORNILLERIA PARA TECHO', 1.00], ['SOLDADOR', 0.10], ['PEON DE CONSTRUCCION', 0.08],
            ], '95.00', '03 TECHO'],
            ['PISO DE CERAMICA 45X45', 'M2', [
                ['CERAMICA DE PISO 45X45', 1.05], ['PEGAMENTO BONDEX GRIS', 0.25],
                ['ALBAÑIL', 0.18], ['PEON DE CONSTRUCCION', 0.08],
            ], '80.00', '04 ACABADOS'],
        ];

        return $this->armarProyecto(
            nombre: 'CASA DE HABITACION RES. LOS PINOS',
            cliente: ['FAMILIA MEJIA CASTRO', '05019007895412'],
            fichas: $fichas,
            estado: EstadoProyecto::EnEjecucion,
            iniciadaHace: 20,
        );
    }

    // ─── Proyecto 2: ALCANTARILLADO (en ejecución, fichas Las Palmas) ─

    private function crearProyectoAlcantarillado(): Proyecto
    {
        // Las fichas ya existen (PresupuestoLasPalmasSeeder). Solo se arma
        // el proyecto con las cantidades del documento real.
        $renglones = [
            ['MARCADO Y TRAZADO',                        '822.54', '01 PRELIMINARES'],
            ['EXCAVACION',                               '1224.02', '02 MOVIMIENTO DE TIERRA'],
            ['RELLENO Y COMPACTADO',                     '616.90', '02 MOVIMIENTO DE TIERRA'],
            ['SUM. E INST. DE TUBERIA PVC 6" RD-42',     '822.54', '03 TUBERIA'],
            ['SUM. E INST. DE TUBERÍA PVC DE 8" RD-41',  '395.65', '03 TUBERIA'],
            ['CONEXION DOMICILIARIA',                    '169.00', '04 CONEXIONES'],
            ['CONSTRUCCIÓN DE CAJAS DE REGISTRO',        '169.00', '04 CONEXIONES'],
            ['SUMINISTRO Y ELABORACIÓN DE POZO',         '23.00',  '05 POZOS'],
            ['CASQUETE Y TAPADERA',                      '23.00',  '05 POZOS'],
        ];

        $proyecto = $this->proyectoBase('ALCANTARILLADO LOTIFICACION LAS PALMAS', ['INVERSIONES LAS PALMAS S. DE R.L.', '05019012345678']);

        if ($proyecto->renglones()->doesntExist()) {
            $agregar = app(AgregarRenglonAProyectoService::class);

            foreach ($renglones as [$nombreFicha, $cantidad, $capitulo]) {
                $ficha = Ficha::query()
                    ->where('zona_id', $this->zona->id)
                    ->where('nombre', $nombreFicha)
                    ->first();

                if ($ficha !== null) {
                    $agregar->ejecutar($proyecto, $ficha, $cantidad, $capitulo);
                }
            }
        }

        $this->transicionar($proyecto, EstadoProyecto::EnEjecucion, iniciadaHace: 45);

        return $proyecto;
    }

    // ─── Proyecto 3: EDIFICIO (aprobado, por iniciar) ────────────────

    private function crearProyectoEdificio(): Proyecto
    {
        $fichas = [
            ['EXCAVACION ESTRUCTURAL CON MAQUINA', 'M3', [
                ['HORA EXCAVADORA CAT 320', 0.06], ['PEON DE CONSTRUCCION', 0.10],
            ], '420.00', '01 MOVIMIENTO DE TIERRA'],
            ['COLUMNA DE CONCRETO 3000 PSI 30X30', 'ML', [
                ['CEMENTO GRIS 42.5 KG', 0.90], ['ARENA DE RIO', 0.06], ['GRAVA TRITURADA 3/4', 0.09],
                ['HIERRO CORRUGADO 1/2 X 6M', 4.00], ['ALAMBRE DE AMARRE', 0.60],
                ['MADERA DE PINO PARA ENCOFRADO', 8.00], ['HORA MEZCLADORA 2 BOLSAS', 0.80],
                ['ARMADOR DE HIERRO', 0.25], ['ALBAÑIL', 0.20], ['PEON DE CONSTRUCCION', 0.35],
            ], '186.00', '02 ESTRUCTURA'],
            ['LOSA DE VIGUETA Y BOVEDILLA', 'M2', [
                ['VIGUETA PRETENSADA', 2.10], ['BOVEDILLA DE CONCRETO', 8.33],
                ['CEMENTO GRIS 42.5 KG', 0.45], ['HIERRO CORRUGADO 3/8 X 6M', 1.50],
                ['ANDAMIOS Y HERRAMIENTA MENOR', 0.10],
                ['ARMADOR DE HIERRO', 0.15], ['ALBAÑIL', 0.20], ['PEON DE CONSTRUCCION', 0.25],
            ], '540.00', '02 ESTRUCTURA'],
            ['FACHADA DE VENTANERIA DE ALUMINIO', 'M2', [
                ['VENTANA DE ALUMINIO Y VIDRIO', 1.00], ['INSTALADOR DE VENTANAS', 0.15],
            ], '96.00', '03 FACHADA'],
        ];

        return $this->armarProyecto(
            nombre: 'EDIFICIO COMERCIAL PLAZA MEDICA 3 NIVELES',
            cliente: ['GRUPO MEDICO OCCIDENTE S.A.', '05019098765432'],
            fichas: $fichas,
            estado: EstadoProyecto::Aprobada,
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Crea las fichas del proyecto (si no existen), el proyecto y sus
     * renglones vía el Service (snapshot de precios correcto).
     *
     * @param array{0: string, 1: string} $cliente [nombre, rtn]
     * @param list<array{0: string, 1: string, 2: list<array{0: string, 1: float}>, 3: string, 4: string}> $fichas
     */
    private function armarProyecto(string $nombre, array $cliente, array $fichas, EstadoProyecto $estado, ?int $iniciadaHace = null): Proyecto
    {
        $calcular = new CalcularPrecioFichaService;
        $creadas = [];

        foreach ($fichas as [$nombreFicha, $unidad, $componentes, $cantidad, $capitulo]) {
            $ficha = Ficha::firstOrCreate(
                ['zona_id' => $this->zona->id, 'nombre' => $nombreFicha],
                [
                    'unidad_medida_id'    => UnidadMedida::where('codigo', $unidad)->firstOrFail()->id,
                    'utilidad_porcentaje' => '25.00',
                    'activa'              => true,
                ],
            );

            if ($ficha->lineas()->doesntExist()) {
                $orden = 0;

                foreach ($componentes as [$nombreItem, $rendimiento]) {
                    $item = Item::query()
                        ->where('zona_id', $this->zona->id)
                        ->where('nombre', $nombreItem)
                        ->firstOrFail();

                    FichaLinea::create([
                        'ficha_id'               => $ficha->id,
                        'tipo'                   => TipoLineaFicha::Item,
                        'orden'                  => $orden++,
                        'item_id'                => $item->id,
                        'rendimiento'            => number_format($rendimiento, 6, '.', ''),
                        'desperdicio_porcentaje' => '0.00',
                    ]);
                }

                $calcular->recalcularYPersistir($ficha->fresh());
            }

            $creadas[] = [$ficha->fresh(), $cantidad, $capitulo];
        }

        $proyecto = $this->proyectoBase($nombre, $cliente);

        if ($proyecto->renglones()->doesntExist()) {
            $agregar = app(AgregarRenglonAProyectoService::class);

            foreach ($creadas as [$ficha, $cantidad, $capitulo]) {
                $agregar->ejecutar($proyecto, $ficha, $cantidad, $capitulo);
            }
        }

        $this->transicionar($proyecto, $estado, $iniciadaHace);

        return $proyecto;
    }

    /**
     * @param array{0: string, 1: string} $cliente
     */
    private function proyectoBase(string $nombre, array $cliente): Proyecto
    {
        $clienteModelo = Cliente::query()->firstWhere('nombre', $cliente[0])
            ?? Cliente::factory()->create(['nombre' => $cliente[0], 'rtn' => $cliente[1]]);

        return Proyecto::query()->firstWhere('nombre', $nombre)
            ?? Proyecto::factory()->create([
                'nombre'     => $nombre,
                'zona_id'    => $this->zona->id,
                'cliente_id' => $clienteModelo->id,
                'estado'     => EstadoProyecto::Borrador->value,
            ]);
    }

    /**
     * Avanza el estado directamente (carga de datos demo — no pasa por la
     * máquina de estados comercial, que exige el flujo completo).
     */
    private function transicionar(Proyecto $proyecto, EstadoProyecto $estado, ?int $iniciadaHace = null): void
    {
        if ($proyecto->estado === $estado) {
            return;
        }

        $datos = ['estado' => $estado->value];

        // Los CHECK exigen fecha_inicio y plazo cuando la obra está viva.
        if ($estado === EstadoProyecto::EnEjecucion) {
            $inicio = now()->subDays($iniciadaHace ?? 15)->startOfDay();

            $datos['modo_plazo'] = 'calendario';
            $datos['plazo_dias'] = 120;
            $datos['fecha_inicio'] = $inicio;
            $datos['fecha_fin_estimada'] = $inicio->copy()->addDays(120);
        }

        $proyecto->update($datos);
    }

    private function item(string $nombre, string $unidad, CategoriaItem $categoria, string $precio): Item
    {
        return Item::firstOrCreate(
            ['zona_id' => $this->zona->id, 'categoria' => $categoria->value, 'nombre' => $nombre],
            [
                'unidad_medida_id' => UnidadMedida::where('codigo', $unidad)->firstOrFail()->id,
                'precio_unitario'  => $precio,
                'activo'           => true,
            ],
        );
    }

    private function crearUnidades(): void
    {
        foreach ([
            ['ML',  'Metro lineal', 'ml'],
            ['M2',  'Metro cuadrado', 'm²'],
            ['M3',  'Metro cúbico', 'm³'],
            ['UND', 'Unidad', null],
            ['JDR', 'Jornada', null],
            ['DIA', 'Día (alquiler equipo)', null],
            ['HRA', 'Hora máquina', 'h'],
            ['BLS', 'Bolsa 42.5 kg', null],
            ['VAR', 'Varilla 6 m', null],
            ['LB',  'Libra', 'lb'],
            ['PT',  'Pie tablar', null],
        ] as [$codigo, $nombre, $simbolo]) {
            UnidadMedida::firstOrCreate(
                ['codigo' => $codigo],
                ['nombre' => $nombre, 'simbolo' => $simbolo, 'activo' => true],
            );
        }
    }
}
