<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoProyecto;
use App\Enums\EstadoRequisicion;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\CuentaPorPagar;
use App\Models\Ficha;
use App\Models\Item;
use App\Models\Maquina;
use App\Models\Material;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use App\Models\User;
use App\Models\Zona;
use App\Services\Compras\AbonarService;
use App\Services\Compras\ConfirmarCompraService;
use App\Services\Inventario\Ubicacion;
use App\Services\Maquinaria\SolicitarMaquinaService;
use App\Services\Proyectos\AgregarRenglonAProyectoService;
use App\Services\Requisiciones\PresupuestoMaterialesProyectoService;
use App\Services\Requisiciones\TransicionarRequisicionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DEMO MES DE OPERACIÓN — deja el sistema como si Constructora Mayap
 * llevara un mes completo operando en Santa Rosa de Copán:
 *
 *  1. Base DemoSantaRosaSeeder: zona SRC, bodega, 6 usuarios por rol
 *     (pass 12345678), base de precios real, maquinaria, fichas APU y
 *     3 proyectos (CASA y ALCANTARILLADO en ejecución, EDIFICIO aprobado).
 *  2. Dos cotizaciones extra: una en BORRADOR y una ENVIADA (reusan
 *     fichas APU existentes de la zona).
 *  3. Tres compras al proveedor (2 a crédito con CxP — una con abono
 *     parcial — y 1 de contado) que meten stock real a la bodega, con
 *     fechas repartidas en el mes.
 *  4. Seis requisiciones en TODOS los estados del flujo, movidas por el
 *     TransicionarRequisicionService con los usuarios reales en la
 *     bitácora: cerrada, discrepancia, en tránsito, requisición de
 *     compra (sin stock), solicitada fresca y solicitada VENCIDA (para
 *     probar la acción Reprogramar).
 *  5. Tres solicitudes de maquinaria: agendada, pendiente (máquina
 *     saturada) y rechazada.
 *
 * Las fechas de negocio y los created_at se retro-datan para que los
 * listados cuenten la historia del mes (el backdate va por DB::table
 * a propósito: no debe disparar eventos ni el guard de vencidas).
 *
 * NO va a producción. Correr UNA sola vez sobre una base recién
 * sembrada (si ya hay requisiciones, aborta para no duplicar):
 *
 *   php artisan db:seed --class=DemoMesOperacionSeeder
 */
class DemoMesOperacionSeeder extends Seeder
{
    private Zona $zona;

    private Bodega $bodega;

    /** @var array<string, User> */
    private array $usuarios = [];

    public function run(): void
    {
        if (Requisicion::query()->exists()) {
            $this->command?->warn('Ya hay requisiciones: este demo se corre una sola vez sobre base limpia. Nada que hacer.');

            return;
        }

        // 1. Base Santa Rosa (idempotente).
        $this->call(DemoSantaRosaSeeder::class);

        $this->zona = Zona::query()->where('codigo', 'SRC')->firstOrFail();
        $this->bodega = Bodega::query()->where('nombre', 'BODEGA SANTA ROSA')->firstOrFail();
        $this->usuarios = [
            'gerente'    => User::query()->where('email', 'gerente@gmail.com')->firstOrFail(),
            'bodeguero'  => User::query()->where('email', 'bodeguero@gmail.com')->firstOrFail(),
            'obra'       => User::query()->where('email', 'obra@gmail.com')->firstOrFail(),
            'maquinaria' => User::query()->where('email', 'maquinaria@gmail.com')->firstOrFail(),
        ];

        $casa = Proyecto::query()->where('nombre', 'like', 'CASA DE HABITACION%')->firstOrFail();
        $alcantarillado = Proyecto::query()->where('nombre', 'like', 'ALCANTARILLADO%')->firstOrFail();

        // 2-5. El mes de operación.
        $this->cotizacionesEnCurso();
        $this->comprasDelMes($casa, $alcantarillado);
        $this->requisicionesDelMes($casa, $alcantarillado);
        $this->solicitudesDeMaquinaria($casa, $alcantarillado);

        $this->command?->info('');
        $this->command?->info('══════════════════════════════════════════════════════');
        $this->command?->info('  DEMO MES DE OPERACIÓN LISTO');
        $this->command?->info('  2 cotizaciones (borrador/enviada) · 3 compras con stock');
        $this->command?->info('  6 requisiciones (cerrada, discrepancia, tránsito,');
        $this->command?->info('  req. de compra, solicitada y VENCIDA para Reprogramar)');
        $this->command?->info('  3 solicitudes de maquinaria (agendada/pendiente/rechazada)');
        $this->command?->info('══════════════════════════════════════════════════════');
    }

    // ─── 2. Cotizaciones en curso (borrador + enviada) ───────────────

    private function cotizacionesEnCurso(): void
    {
        $this->cotizacion(
            nombre: 'MURO PERIMETRAL Y CANCHA LICEO LAS COLINAS',
            cliente: ['LICEO BILINGUE LAS COLINAS', '08019012457896'],
            estado: EstadoProyecto::Borrador,
            fichas: [
                ['ZAPATA CORRIDA 40X20 CM', '82.00', '01 CIMENTACION'],
                ['PARED DE BLOQUE 15 REFORZADA', '246.00', '02 MURO'],
                ['REPELLO Y PULIDO DE PAREDES', '492.00', '02 MURO'],
            ],
        );

        $this->cotizacion(
            nombre: 'REMODELACION LOCAL COMERCIAL PLAZA SARO',
            cliente: ['INVERSIONES PLAZA SARO S.A.', '08019985033221'],
            estado: EstadoProyecto::Enviada,
            fichas: [
                ['PARED DE BLOQUE 15 REFORZADA', '64.00', '01 OBRA GRIS'],
                ['REPELLO Y PULIDO DE PAREDES', '128.00', '01 OBRA GRIS'],
                ['PISO DE CERAMICA 45X45', '110.00', '02 ACABADOS'],
            ],
        );
    }

    /**
     * @param array{0: string, 1: string} $cliente [nombre, rtn]
     * @param array<int, array{0: string, 1: string, 2: string}> $fichas [nombre ficha, cantidad, capítulo]
     */
    private function cotizacion(string $nombre, array $cliente, EstadoProyecto $estado, array $fichas): void
    {
        if (Proyecto::query()->where('nombre', $nombre)->exists()) {
            return;
        }

        $clienteModelo = Cliente::query()->firstWhere('nombre', $cliente[0])
            ?? Cliente::factory()->create(['nombre' => $cliente[0], 'rtn' => $cliente[1]]);

        // Siempre nace en Borrador: agregar renglones exige proyecto
        // editable. El estado final se fija al terminar de armarla.
        $proyecto = Proyecto::factory()->create([
            'nombre'     => $nombre,
            'zona_id'    => $this->zona->id,
            'cliente_id' => $clienteModelo->id,
            'estado'     => EstadoProyecto::Borrador->value,
        ]);

        $agregar = app(AgregarRenglonAProyectoService::class);

        foreach ($fichas as [$nombreFicha, $cantidad, $capitulo]) {
            $ficha = Ficha::query()
                ->where('zona_id', $this->zona->id)
                ->where('nombre', $nombreFicha)
                ->first();

            if ($ficha !== null) {
                $agregar->ejecutar($proyecto, $ficha, $cantidad, $capitulo);
            }
        }

        if ($estado !== EstadoProyecto::Borrador) {
            $proyecto->update(['estado' => $estado->value]);
        }
    }

    // ─── 3. Compras del mes (stock real en bodega) ───────────────────

    private function comprasDelMes(Proyecto $casa, Proyecto $alcantarillado): void
    {
        $ferreteria = Proveedor::query()->firstWhere('nombre', 'FERRETERIA COPAN S.A. DE C.V.')
            ?? Proveedor::factory()->aCredito(30)->create(['nombre' => 'FERRETERIA COPAN S.A. DE C.V.']);
        $agregados = Proveedor::query()->firstWhere('nombre', 'MATERIALES Y AGREGADOS DE OCCIDENTE')
            ?? Proveedor::factory()->create(['nombre' => 'MATERIALES Y AGREGADOS DE OCCIDENTE']);

        // Compra el 60% del presupuesto de los primeros materiales de cada
        // obra — deja stock de sobra para las requisiciones del mes.
        $materialesCasa = $this->presupuesto($casa, 4);
        $materialesAlc = $this->presupuesto($alcantarillado, 4);

        // El abono es una FRACCIÓN del saldo real de la CxP (0.5 = 50%):
        // un monto fijo puede exceder el saldo y el servicio lo bloquea.
        $this->compra($ferreteria, aCredito: true, haceDias: 24, factura: 'F-2026-04481', materiales: $materialesCasa, abono: '0.5');
        $this->compra($agregados, aCredito: false, haceDias: 15, factura: 'F-2026-01102', materiales: $materialesAlc);
        // Reabastecimiento reciente de la casa (mismos materiales).
        $this->compra($ferreteria, aCredito: true, haceDias: 5, factura: 'F-2026-04702', materiales: $materialesCasa);
    }

    /**
     * Primeros $cuantos materiales presupuestados de la obra con su
     * cantidad a comprar (60% del presupuesto, mínimo 10).
     *
     * @return array<int, string> material_id => cantidad
     */
    private function presupuesto(Proyecto $obra, int $cuantos): array
    {
        return app(PresupuestoMaterialesProyectoService::class)
            ->porProyecto($obra->id)
            ->filter(fn ($pm): bool => bccomp($pm->presupuestado, '0', 4) > 0)
            ->take($cuantos)
            ->mapWithKeys(function ($pm): array {
                $cantidad = bcmul($pm->presupuestado, '0.6', 0);

                return [$pm->materialId => bccomp($cantidad, '10', 0) < 0 ? '10' : $cantidad];
            })
            ->all();
    }

    /**
     * @param array<int, string> $materiales material_id => cantidad
     */
    private function compra(Proveedor $proveedor, bool $aCredito, int $haceDias, string $factura, array $materiales, ?string $abono = null): void
    {
        // Re-ejecución del seeder tras un fallo a mitad: la compra que ya
        // entró (stock incluido) no se duplica.
        if (Compra::query()->where('numero_factura', $factura)->exists()) {
            return;
        }

        $factory = Compra::factory()->paraProveedor($proveedor)->paraBodega($this->bodega);

        if ($aCredito) {
            $factory = $factory->aCredito();
        }

        $compra = $factory->create([
            'fecha'          => today()->subDays($haceDias),
            'numero_factura' => $factura,
        ]);

        foreach ($materiales as $materialId => $cantidad) {
            CompraLinea::factory()
                ->paraMaterial(Material::query()->findOrFail($materialId))
                ->create([
                    'compra_id'      => $compra->id,
                    'cantidad'       => $cantidad,
                    'costo_unitario' => $this->costoDemo($materialId),
                ]);
        }

        app(ConfirmarCompraService::class)->confirmar($compra, $this->usuarios['gerente']->id);

        if ($abono !== null) {
            $cxp = CuentaPorPagar::query()->where('compra_id', $compra->id)->first();

            if ($cxp !== null) {
                app(AbonarService::class)->abonar($cxp, bcmul((string) $cxp->saldo, $abono, 2));
            }
        }

        DB::table('compras')->where('id', $compra->id)
            ->update(['created_at' => now()->subDays($haceDias)]);
    }

    /**
     * Costo demo de compra: 75% del precio de venta del item de la zona
     * (margen realista). Sin item de precio, un costo genérico.
     */
    private function costoDemo(int $materialId): string
    {
        $precio = Item::query()
            ->where('zona_id', $this->zona->id)
            ->where('material_id', $materialId)
            ->value('precio_unitario');

        return $precio !== null
            ? bcmul((string) $precio, '0.75', 2)
            : '185.00';
    }

    // ─── 4. Requisiciones del mes (todos los estados) ────────────────

    private function requisicionesDelMes(Proyecto $casa, Proyecto $alcantarillado): void
    {
        $flujo = app(TransicionarRequisicionService::class);
        $gerente = $this->usuarios['gerente']->id;
        $bodeguero = $this->usuarios['bodeguero']->id;
        $obra = $this->usuarios['obra']->id;

        // R1 — CASA, hace 18 días: flujo completo hasta CERRADA.
        $r1 = $this->requisicion($casa, porcentaje: '0.15');
        $flujo->autorizar($r1, userId: $gerente, nota: 'AUTORIZADA COMPLETA PARA ARRANQUE DE PAREDES');
        $flujo->despachar($r1, Ubicacion::bodega($this->bodega->id), $bodeguero);
        $flujo->marcarEnTransito($r1, $bodeguero);
        $flujo->recibir($r1->fresh(), userId: $obra);
        $flujo->conciliar($r1->fresh(), $gerente);
        $this->backdatear($r1, solicitudHace: 18, necesariaHace: 14);

        // R2 — ALCANTARILLADO, hace 13 días: recibieron de menos → DISCREPANCIA.
        $r2 = $this->requisicion($alcantarillado, porcentaje: '0.12');
        $flujo->autorizar($r2, userId: $gerente);
        $flujo->despachar($r2, Ubicacion::bodega($this->bodega->id), $bodeguero);
        $flujo->marcarEnTransito($r2, $bodeguero);
        $primeraLinea = $r2->fresh()->lineas()->orderBy('id')->firstOrFail();
        $recibidas = [$primeraLinea->id => bcmul((string) $primeraLinea->cantidad_despachada, '0.9', 4)];
        $flujo->recibir($r2->fresh(), $recibidas, $obra, 'LLEGO INCOMPLETO, SE ROMPIO PARTE EN EL CAMINO');
        $flujo->conciliar($r2->fresh(), $gerente);
        $this->backdatear($r2, solicitudHace: 13, necesariaHace: 9);

        // R3 — CASA, hace 7 días: despachada y EN TRANSITO hacia la obra.
        $r3 = $this->requisicion($casa, porcentaje: '0.10');
        $flujo->autorizar($r3, userId: $gerente);
        $flujo->despachar($r3, Ubicacion::bodega($this->bodega->id), $bodeguero);
        $flujo->marcarEnTransito($r3, $bodeguero, 'SALE EN EL CAMION DE LA TARDE');
        $this->backdatear($r3, solicitudHace: 7, necesariaHace: -1);

        // R4 — ALCANTARILLADO, hace 6 días: pide MÁS de lo que hay en
        // bodega → el despacho la manda a REQUISICION DE COMPRA.
        $r4 = $this->requisicion($alcantarillado, porcentaje: '3.0');
        $flujo->autorizar($r4, userId: $gerente, nota: 'URGE PARA EL COLECTOR PRINCIPAL');
        $flujo->despachar($r4, Ubicacion::bodega($this->bodega->id), $bodeguero);
        $this->backdatear($r4, solicitudHace: 6, necesariaHace: -2);

        // R5 — CASA, hace 2 días: SOLICITADA fresca, lista para autorizar.
        $r5 = $this->requisicion($casa, porcentaje: '0.08');
        $this->backdatear($r5, solicitudHace: 2, necesariaHace: -3);

        // R6 — ALCANTARILLADO: SOLICITADA con la fecha necesaria VENCIDA
        // hace 4 días — el caso que obliga a Reprogramar (o Rechazar).
        $r6 = $this->requisicion($alcantarillado, porcentaje: '0.10');
        $this->backdatear($r6, solicitudHace: 11, necesariaHace: 4);
    }

    /**
     * Crea una requisición Solicitada de la obra con sus 3 primeros
     * materiales presupuestados, pidiendo el porcentaje dado del
     * presupuesto de cada uno. Fechas temporales EN FUTURO para pasar el
     * guard de vencidas al transicionar; backdatear() pone las reales.
     */
    private function requisicion(Proyecto $obra, string $porcentaje): Requisicion
    {
        $requisicion = Requisicion::create([
            'proyecto_id'     => $obra->id,
            'estado'          => EstadoRequisicion::Solicitada->value,
            'solicitante_id'  => $this->usuarios['obra']->id,
            'fecha_solicitud' => today(),
            'fecha_necesaria' => today()->addDays(30),
        ]);

        $materiales = app(PresupuestoMaterialesProyectoService::class)
            ->porProyecto($obra->id)
            ->filter(fn ($pm): bool => bccomp($pm->presupuestado, '0', 4) > 0)
            ->take(3);

        foreach ($materiales as $pm) {
            $cantidad = bcmul($pm->presupuestado, $porcentaje, 0);

            RequisicionLinea::factory()->create([
                'requisicion_id'      => $requisicion->id,
                'material_id'         => $pm->materialId,
                'cantidad_solicitada' => bccomp($cantidad, '1', 0) < 0 ? '1' : $cantidad,
            ]);
        }

        return $requisicion->fresh();
    }

    /**
     * Retro-data la requisición y su bitácora para contar la historia del
     * mes. Directo por DB::table: no debe disparar eventos ni guards
     * ($necesariaHace negativo = fecha necesaria en el futuro).
     */
    private function backdatear(Requisicion $requisicion, int $solicitudHace, int $necesariaHace): void
    {
        DB::table('requisiciones')->where('id', $requisicion->id)->update([
            'fecha_solicitud' => today()->subDays($solicitudHace),
            'fecha_necesaria' => today()->subDays($necesariaHace),
            'created_at'      => now()->subDays($solicitudHace),
        ]);

        // Las transiciones se reparten: una por día desde la solicitud.
        $transiciones = DB::table('requisicion_transiciones')
            ->where('requisicion_id', $requisicion->id)
            ->orderBy('id')
            ->pluck('id');

        foreach ($transiciones as $i => $id) {
            $fecha = now()->subDays(max($solicitudHace - $i, 0));

            DB::table('requisicion_transiciones')->where('id', $id)
                ->update(['created_at' => $fecha, 'updated_at' => $fecha]);
        }
    }

    // ─── 5. Solicitudes de maquinaria ────────────────────────────────

    private function solicitudesDeMaquinaria(Proyecto $casa, Proyecto $alcantarillado): void
    {
        $maquinas = Maquina::query()->orderBy('id')->take(2)->get();

        if ($maquinas->count() < 2) {
            $this->command?->warn('Sin maquinaria suficiente para las solicitudes demo — se omiten.');

            return;
        }

        $solicitar = app(SolicitarMaquinaService::class);
        $obraUser = $this->usuarios['obra']->id;
        $manana = today()->addDay()->toDateString();

        try {
            // S1: la agenda tiene el día libre → queda AGENDADA sola.
            $solicitar->crear($casa->id, $maquinas[0]->id, $manana, '07:00', notas: 'NIVELACION DE TERRAZA', userId: $obraUser);

            // S2: la MISMA máquina el MISMO día para otra obra → PENDIENTE
            // (saturada: maquinaria decide).
            $solicitar->crear($alcantarillado->id, $maquinas[0]->id, $manana, '08:00', notas: 'ZANJEO COLECTOR NORTE', userId: $obraUser);

            // S3: se pide y maquinaria la RECHAZA con motivo.
            $s3 = $solicitar->crear($casa->id, $maquinas[1]->id, today()->addDays(3)->toDateString(), '07:30', notas: 'ACARREO DE MATERIAL', userId: $obraUser);

            if ($s3->estado->value === 'pendiente') {
                $solicitar->rechazar($s3, 'LA MAQUINA ENTRA A MANTENIMIENTO ESE DIA', $this->usuarios['maquinaria']->id);
            } else {
                // Quedó agendada de una vez: creamos otra y la rechazamos.
                $s3b = $solicitar->crear($alcantarillado->id, $maquinas[1]->id, today()->addDays(3)->toDateString(), '13:00', notas: 'ACARREO DE TUBERIA', userId: $obraUser);
                $solicitar->rechazar($s3b, 'LA MAQUINA ENTRA A MANTENIMIENTO ESE DIA', $this->usuarios['maquinaria']->id);
            }
        } catch (Throwable $e) {
            $this->command?->warn("Solicitudes de maquinaria demo incompletas: {$e->getMessage()}");
        }
    }
}
