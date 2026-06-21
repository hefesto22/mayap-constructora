<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CategoriaItem;
use App\Enums\EstadoProyecto;
use App\Enums\TipoPago;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\CuentaPorCobrar;
use App\Models\CuentaPorPagar;
use App\Models\Empleado;
use App\Models\Item;
use App\Models\Maquina;
use App\Models\Planilla;
use App\Models\PlanillaLinea;
use App\Models\Proveedor;
use App\Models\Proyecto;
use App\Models\Zona;
use App\Services\Cobranza\CobrarService;
use App\Services\Compras\AbonarService;
use App\Services\Compras\ConfirmarCompraService;
use App\Services\Inventario\RegistrarMovimientoService;
use App\Services\Inventario\Ubicacion;
use App\Services\Maquinaria\AsignarMaquinaService;
use App\Services\Maquinaria\RegistrarConsumoCombustibleService;
use App\Services\Maquinaria\RegistrarParteService;
use App\Services\Planilla\ProcesarPlanillaService;
use Illuminate\Database\Seeder;

/**
 * Demo operativa integral: crea UNA obra realista y la recorre de punta a
 * punta para poblar el reporte de costo y los módulos del sistema:
 *
 *   compra a crédito → confirmar (stock + CxP + abono) → despacho de material
 *   a la obra → máquina asignada con parte de horas + combustible → planilla
 *   cerrada con pagos a la obra → cuenta por cobrar al cliente con un cobro.
 *
 * Resultado: la obra muestra costo real (materiales + maquinaria + mano de
 * obra) vs presupuesto, con su nivel de alerta, y el dashboard refleja todo.
 *
 * Ejecutar: php artisan db:seed --class=DemoOperativoSeeder
 */
class DemoOperativoSeeder extends Seeder
{
    public function run(): void
    {
        // Reutiliza la zona si ya existe (el código es único) para que el
        // seeder se pueda correr aunque ya haya catálogos cargados.
        $zona = Zona::query()->firstOrCreate(
            ['codigo' => 'SRC'],
            ['nombre' => 'SANTA ROSA DE COPÁN', 'activa' => true],
        );

        $cliente = Cliente::factory()->create([
            'nombre'   => 'ALCALDÍA MUNICIPAL DE SANTA ROSA',
            'telefono' => '2662-0001',
        ]);

        // Obra aprobada (en ejecución) con presupuesto de venta de L. 500,000.
        $obra = Proyecto::factory()
            ->paraCliente($cliente)
            ->enZona($zona)
            ->create([
                'nombre'         => 'PAVIMENTACIÓN CALLE PRINCIPAL BARRIO EL CALVARIO',
                'estado'         => EstadoProyecto::Aprobada->value,
                'subtotal_cache' => 500000,
                'isv_cache'      => 75000,
                'total_cache'    => 575000,
            ]);

        $bodega = Bodega::factory()->create(['nombre' => 'BODEGA CENTRAL']);

        $cemento = Item::factory()->enZona($zona)->deCategoria(CategoriaItem::Materiales)
            ->create(['nombre' => 'CEMENTO GRIS 42.5KG', 'precio_unitario' => 250]);
        $hierro = Item::factory()->enZona($zona)->deCategoria(CategoriaItem::Materiales)
            ->create(['nombre' => 'HIERRO #4 LEGÍTIMO', 'precio_unitario' => 320]);

        $this->comprasYMateriales($obra, $bodega, $cemento, $hierro);
        $this->maquinaria($obra);
        $this->planilla($obra);
        $this->cuentaPorCobrar($obra, $cliente);

        $this->command?->info("Demo lista: obra {$obra->codigo} — {$obra->nombre}.");
        $this->command?->info('Revisa Comercial → Proyectos → Costos, y el dashboard.');
    }

    private function comprasYMateriales(Proyecto $obra, Bodega $bodega, Item $cemento, Item $hierro): void
    {
        $proveedor = Proveedor::factory()->aCredito(30)->create([
            'nombre' => 'FERRETERÍA EL CONSTRUCTOR S. DE R.L.',
        ]);

        // Compra a crédito: 400 sacos cemento @ 250 + 200 varillas hierro @ 320.
        $compra = Compra::factory()
            ->paraProveedor($proveedor)
            ->paraBodega($bodega)
            ->aCredito()
            ->create(['numero_factura' => 'F-00123']);

        CompraLinea::factory()->paraItem($cemento)->create(['compra_id' => $compra->id, 'cantidad' => 400, 'costo_unitario' => 250]);
        CompraLinea::factory()->paraItem($hierro)->create(['compra_id' => $compra->id, 'cantidad' => 200, 'costo_unitario' => 320]);

        app(ConfirmarCompraService::class)->confirmar($compra);

        // Abono parcial a la cuenta por pagar generada.
        $cxp = CuentaPorPagar::query()->where('compra_id', $compra->id)->first();

        if ($cxp !== null) {
            app(AbonarService::class)->abonar($cxp, '50000');
        }

        // Despacho de material a la obra (imputa costo de materiales a la obra).
        $inventario = app(RegistrarMovimientoService::class);
        $inventario->salidaDespacho(
            itemId: $cemento->id,
            origen: Ubicacion::bodega($bodega->id),
            destino: Ubicacion::obra($obra->id),
            cantidad: '300',
        );
        $inventario->salidaDespacho(
            itemId: $hierro->id,
            origen: Ubicacion::bodega($bodega->id),
            destino: Ubicacion::obra($obra->id),
            cantidad: '150',
        );
    }

    private function maquinaria(Proyecto $obra): void
    {
        $excavadora = Maquina::factory()->create([
            'nombre'           => 'EXCAVADORA CAT 320',
            'marca'            => 'CATERPILLAR',
            'tarifa_hora'      => 1800,
            'jornada_horas'    => 8,
            'horometro_actual' => 1200,
        ]);

        $asignacion = app(AsignarMaquinaService::class)->asignar($excavadora, $obra->id, tarifaPactada: '1800');

        // 3 días de trabajo por horómetro (8 h cada uno) + combustible. Las
        // lecturas se pasan explícitas porque reutilizamos la misma instancia
        // de asignación entre llamadas (la relación maquina quedaría stale).
        $partes = app(RegistrarParteService::class);
        $partes->registrarPorHorometro($asignacion, lecturaFinal: '1208', lecturaInicial: '1200');
        $partes->registrarPorHorometro($asignacion, lecturaFinal: '1216', lecturaInicial: '1208');
        $partes->registrarPorHorometro($asignacion, lecturaFinal: '1224', lecturaInicial: '1216');

        app(RegistrarConsumoCombustibleService::class)->registrar($asignacion, litros: '120', precioLitro: '110');
    }

    private function planilla(Proyecto $obra): void
    {
        $maestro = Empleado::factory()->salario(7000)->create([
            'nombre' => 'PEDRO MARTÍNEZ', 'cargo' => 'MAESTRO DE OBRA',
        ]);
        $albanil = Empleado::factory()->create([
            'nombre' => 'JUAN LÓPEZ', 'cargo' => 'ALBAÑIL', 'tarifa_base' => 500,
        ]);

        $planilla = Planilla::factory()->create();

        PlanillaLinea::factory()->create([
            'planilla_id'     => $planilla->id,
            'empleado_id'     => $maestro->id,
            'proyecto_id'     => $obra->id,
            'tipo_pago'       => TipoPago::Salario->value,
            'dias_trabajados' => null,
            'tarifa_aplicada' => 7000,
            'monto_bruto'     => 0,
        ]);
        PlanillaLinea::factory()->create([
            'planilla_id'     => $planilla->id,
            'empleado_id'     => $albanil->id,
            'proyecto_id'     => $obra->id,
            'tipo_pago'       => TipoPago::Jornal->value,
            'dias_trabajados' => 6,
            'tarifa_aplicada' => 500,
            'monto_bruto'     => 0,
        ]);

        app(ProcesarPlanillaService::class)->cerrar($planilla);
    }

    private function cuentaPorCobrar(Proyecto $obra, Cliente $cliente): void
    {
        $cxc = CuentaPorCobrar::factory()->create([
            'cliente_id'     => $cliente->id,
            'proyecto_id'    => $obra->id,
            'concepto'       => 'ANTICIPO 40% DEL CONTRATO',
            'monto_original' => 200000,
            'saldo'          => 200000,
        ]);

        app(CobrarService::class)->cobrar($cxc, '80000');
    }
}
