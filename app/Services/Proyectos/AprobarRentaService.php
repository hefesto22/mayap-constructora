<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Enums\EstadoProyecto;
use App\Enums\ModoPlazo;
use App\Enums\UnidadRenta;
use App\Exceptions\Proyectos\RentaInvalidaException;
use App\Models\CuentaPorCobrar;
use App\Models\Proyecto;
use App\Models\ProyectoLineaRenta;
use App\Services\Maquinaria\AgendarMaquinaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aprueba una renta de maquinaria y deja TODO listo en un solo paso:
 *
 *  1. Recalcula los totales (lo cotizado es lo que se cobra).
 *  2. Transiciona la cotización a Aprobada (pasando por Enviada si
 *     estaba en Borrador — la máquina de estados no se salta pasos).
 *  3. Auto-inicia la ejecución: fecha_inicio = primera llegada,
 *     plazo = días que cubre la renta, modo calendario. Una renta no
 *     tiene "arranque de obra" separado: aprobada = va.
 *  4. Agenda cada línea en el calendario de maquinaria (lote que
 *     salta-y-reporta: un choque de mantenimiento no aborta la
 *     aprobación, queda en la bitácora para resolverlo a mano).
 *     Rentas NO excluyen domingos: si el cliente renta el domingo,
 *     se trabaja el domingo.
 *  5. Genera la cuenta por cobrar por el total cotizado, con
 *     vencimiento según la condición del cliente (contado = vence
 *     el mismo día; crédito = a N días). Anticipos y abonos entran
 *     después por CobrarService, cuando el cliente pague.
 *
 * El extra por horas reales > pactadas se cobra al FINALIZAR
 * (FinalizarRentaService) — aquí solo se pacta.
 */
final class AprobarRentaService
{
    public function __construct(
        private readonly CalcularPrecioProyectoService $calculadora,
        private readonly TransicionComercialProyectoService $transiciones,
        private readonly IniciarProyectoService $iniciador,
        private readonly AgendarMaquinaService $agenda,
    ) {}

    /**
     * @return array{proyecto: Proyecto, cuenta: CuentaPorCobrar|null, saltados: list<string>}
     */
    public function aprobar(Proyecto $proyecto, ?int $userId = null): array
    {
        if (! $proyecto->esRenta()) {
            throw RentaInvalidaException::noEsRenta($proyecto->codigo);
        }

        $proyecto->loadMissing('lineasRenta.maquina', 'cliente');

        if ($proyecto->lineasRenta->isEmpty()) {
            throw RentaInvalidaException::sinLineas($proyecto->codigo);
        }

        return DB::transaction(function () use ($proyecto, $userId): array {
            // 1. Totales frescos: lo que se apruebe es lo que se cobra.
            $proyecto = $this->calculadora->recalcular($proyecto);

            // 2. Máquina de estados sin saltos: Borrador pasa por Enviada.
            if ($proyecto->estado === EstadoProyecto::Borrador) {
                $proyecto = $this->transiciones->cambiar(
                    $proyecto,
                    EstadoProyecto::Enviada,
                    'Renta: aprobación directa',
                );
            }

            $proyecto = $this->transiciones->cambiar(
                $proyecto,
                EstadoProyecto::Aprobada,
                'Renta aprobada por el cliente',
            );

            // 3. Auto-inicio: la renta arranca con la primera llegada.
            [$inicio, $plazoDias] = $this->ventanaDeLaRenta($proyecto);

            $proyecto = $this->iniciador->ejecutar(
                $proyecto,
                $inicio,
                $plazoDias,
                ModoPlazo::Calendario,
            );

            // 4. Al calendario: cada línea con su fecha y hora de llegada.
            $saltados = $this->agendarLineas($proyecto, $userId);

            // 5. La deuda nace al aprobar; vence según el cliente.
            $cuenta = $this->generarCuentaPorCobrar($proyecto, $userId);

            return [
                'proyecto' => $proyecto->refresh(),
                'cuenta'   => $cuenta,
                'saltados' => $saltados,
            ];
        });
    }

    /**
     * Ventana [primera llegada, último día pactado] de la renta.
     *
     * @return array{0: Carbon, 1: int}
     */
    private function ventanaDeLaRenta(Proyecto $proyecto): array
    {
        $inicio = null;
        $fin = null;

        foreach ($proyecto->lineasRenta as $linea) {
            $desde = $linea->fecha_llegada->copy()->startOfDay();
            $hasta = $this->ultimoDiaDeLinea($linea);

            $inicio = ($inicio === null || $desde->lt($inicio)) ? $desde : $inicio;
            $fin = ($fin === null || $hasta->gt($fin)) ? $hasta : $fin;
        }

        /** @var Carbon $inicio */
        /** @var Carbon $fin */
        $plazoDias = max(1, (int) $inicio->diffInDays($fin) + 1);

        return [$inicio, $plazoDias];
    }

    /**
     * Último día que ocupa una línea: por horas es el mismo día de
     * llegada; por días, llegada + (días - 1).
     */
    private function ultimoDiaDeLinea(ProyectoLineaRenta $linea): Carbon
    {
        $dias = $linea->unidad === UnidadRenta::Dia
            ? max(1, (int) ceil((float) $linea->cantidad))
            : 1;

        return $linea->fecha_llegada->copy()->startOfDay()->addDays($dias - 1);
    }

    /**
     * Agenda cada línea (lote salta-y-reporta). Devuelve los días que
     * no se pudieron agendar, ya registrados en la bitácora.
     *
     * @return list<string>
     */
    private function agendarLineas(Proyecto $proyecto, ?int $userId): array
    {
        $saltados = [];

        foreach ($proyecto->lineasRenta as $linea) {
            $resultado = $this->agenda->agendarLote(
                [$linea->maquina_id],
                $proyecto->id,
                $linea->fecha_llegada->toDateString(),
                $this->ultimoDiaDeLinea($linea)->toDateString(),
                excluirDomingos: false,
                notas: 'RENTA '.$proyecto->codigo,
                userId: $userId,
                horaEntrada: $linea->hora_llegada,
            );

            $saltados = [...$saltados, ...$resultado['saltados']];
        }

        if ($saltados !== []) {
            activity('renta')
                ->performedOn($proyecto)
                ->withProperties(['saltados' => $saltados])
                ->event('agenda_incompleta')
                ->log("Renta {$proyecto->codigo}: días sin agendar al aprobar (resolver a mano)");
        }

        return $saltados;
    }

    /**
     * Genera la CxC por el total cotizado. Total en cero (renta de
     * cortesía) no genera deuda.
     */
    private function generarCuentaPorCobrar(Proyecto $proyecto, ?int $userId): ?CuentaPorCobrar
    {
        if (bccomp((string) $proyecto->total_cache, '0', 2) <= 0) {
            return null;
        }

        $emision = today();

        $cuenta = CuentaPorCobrar::create([
            'cliente_id'        => $proyecto->cliente_id,
            'proyecto_id'       => $proyecto->id,
            'concepto'          => 'RENTA DE MAQUINARIA '.$proyecto->codigo,
            'monto_original'    => (string) $proyecto->total_cache,
            'saldo'             => (string) $proyecto->total_cache,
            'fecha_emision'     => $emision->toDateString(),
            'fecha_vencimiento' => $proyecto->cliente->fechaVencimientoDesde($emision)->toDateString(),
            'estado'            => 'pendiente',
        ]);

        activity('cobranza')
            ->performedOn($cuenta)
            ->causedBy($userId)
            ->withProperties([
                'proyecto'    => $proyecto->codigo,
                'monto'       => (string) $cuenta->monto_original,
                'vencimiento' => $cuenta->fecha_vencimiento->toDateString(),
                'condicion'   => $proyecto->cliente->condicion_pago->value,
            ])
            ->event('cuenta_generada')
            ->log("CxC {$cuenta->codigo} generada al aprobar la renta {$proyecto->codigo}");

        return $cuenta;
    }
}
