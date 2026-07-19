<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Enums\EstadoProyecto;
use App\Enums\UnidadRenta;
use App\Exceptions\Proyectos\RentaInvalidaException;
use App\Models\CuentaPorCobrar;
use App\Models\Proyecto;
use App\Models\ProyectoLineaRenta;
use App\Services\Cobranza\AjustarCuentaPorCobrarService;
use App\Services\Maquinaria\AgendarMaquinaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Extiende una renta ya aprobada: "el cliente quiere más horas/días".
 *
 * NUNCA edita las líneas originales (lo pactado y lo ya trabajado son
 * historia): agrega una línea nueva marcada es_extension, la agenda en
 * el calendario y SUBE la cuenta por cobrar por el valor de la
 * extensión (subtotal + ISV si el proyecto lo aplica).
 *
 * Solo procede con el proyecto Aprobado, En ejecución o Pausado. Una
 * renta Finalizada no se extiende — se hace otra renta (duplicar).
 */
final class ExtenderRentaService
{
    private const int SCALE = 2;

    public function __construct(
        private readonly AgregarLineaRentaService $lineas,
        private readonly AgendarMaquinaService $agenda,
        private readonly AjustarCuentaPorCobrarService $ajustes,
    ) {}

    /**
     * @return array{linea: ProyectoLineaRenta, saltados: list<string>}
     */
    public function extender(
        Proyecto $proyecto,
        int $maquinaId,
        UnidadRenta $unidad,
        string $cantidad,
        string $fechaLlegada,
        ?string $horaLlegada = null,
        ?string $tarifa = null,
        ?string $notas = null,
        ?int $userId = null,
    ): array {
        if (! $proyecto->esRenta()) {
            throw RentaInvalidaException::noEsRenta($proyecto->codigo);
        }

        $estadosVivos = [EstadoProyecto::Aprobada, EstadoProyecto::EnEjecucion, EstadoProyecto::Pausada];

        if (! in_array($proyecto->estado, $estadosVivos, true)) {
            throw RentaInvalidaException::estadoNoPermiteExtender($proyecto->estado);
        }

        return DB::transaction(function () use ($proyecto, $maquinaId, $unidad, $cantidad, $fechaLlegada, $horaLlegada, $tarifa, $notas, $userId): array {
            $linea = $this->lineas->agregar(
                $proyecto,
                $maquinaId,
                $unidad,
                $cantidad,
                $fechaLlegada,
                $horaLlegada,
                $tarifa,
                $notas,
                esExtension: true,
            );

            // Al calendario (salta-y-reporta, igual que al aprobar).
            $resultado = $this->agenda->agendarLote(
                [$linea->maquina_id],
                $proyecto->id,
                $linea->fecha_llegada->toDateString(),
                $this->ultimoDiaDeLinea($linea)->toDateString(),
                excluirDomingos: false,
                notas: 'EXTENSIÓN RENTA '.$proyecto->codigo,
                userId: $userId,
                horaEntrada: $linea->hora_llegada,
            );

            // La deuda crece lo que crece la renta (con su ISV).
            $this->aumentarCuenta($proyecto, $linea, $userId);

            activity('renta')
                ->performedOn($proyecto)
                ->causedBy($userId)
                ->withProperties([
                    'linea_id' => $linea->id,
                    'maquina'  => $linea->maquina->nombre,
                    'detalle'  => $linea->etiqueta,
                    'subtotal' => (string) $linea->subtotal_cache,
                    'saltados' => $resultado['saltados'],
                ])
                ->event('renta_extendida')
                ->log("Renta {$proyecto->codigo} extendida: {$linea->etiqueta}");

            return ['linea' => $linea, 'saltados' => $resultado['saltados']];
        });
    }

    private function ultimoDiaDeLinea(ProyectoLineaRenta $linea): Carbon
    {
        $dias = $linea->unidad === UnidadRenta::Dia
            ? max(1, (int) ceil((float) $linea->cantidad))
            : 1;

        return $linea->fecha_llegada->copy()->startOfDay()->addDays($dias - 1);
    }

    /**
     * Sube la CxC del proyecto por el valor de la extensión. Si la
     * renta no tiene cuenta (total original en cero), la extensión
     * tampoco la crea sola — se reporta para resolver a mano.
     */
    private function aumentarCuenta(Proyecto $proyecto, ProyectoLineaRenta $linea, ?int $userId): void
    {
        $monto = (string) $linea->subtotal_cache;

        if ($proyecto->aplica_isv) {
            $factor = bcadd('1', bcdiv((string) $proyecto->isv_porcentaje, '100', 6), 6);
            $monto = bcadd(bcmul($monto, $factor, 4), '0.005', self::SCALE);
        }

        $cuenta = CuentaPorCobrar::query()
            ->where('proyecto_id', $proyecto->id)
            ->latest('id')
            ->first();

        if ($cuenta === null) {
            throw RentaInvalidaException::sinCuentaPorCobrar($proyecto->codigo);
        }

        $this->ajustes->aumentar(
            $cuenta,
            $monto,
            'EXTENSIÓN DE RENTA '.$proyecto->codigo.': '.$linea->etiqueta,
            $userId,
        );
    }
}
