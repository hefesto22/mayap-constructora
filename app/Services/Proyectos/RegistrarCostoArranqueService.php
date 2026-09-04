<?php

declare(strict_types=1);

namespace App\Services\Proyectos;

use App\Enums\EstadoProyecto;
use App\Enums\RubroCostoArranque;
use App\Exceptions\Proyectos\DatosEjecucionInvalidosException;
use App\Models\CostoArranqueProyecto;
use App\Models\Proyecto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona las PARTIDAS del gasto que una obra heredada ya arrastraba antes
 * de entrar al sistema, y mantiene el cache por rubro en el proyecto.
 *
 * Por qué partidas y no un solo monto: el arrastre no se recuerda de una
 * sentada. Van apareciendo facturas viejas de a poco ("faltó el hierro de
 * mayo"), y un total que se reescribe obliga a sumar de cabeza y no deja
 * rastro de qué lo compone ni de quién lo dijo.
 *
 * Por qué hace falta: CostoProyectoService solo suma lo REGISTRADO
 * (movimientos de inventario, partes de trabajo + combustible, planillas
 * cerradas). Sin esto una obra heredada entra con costo real 0 — margen
 * 100%, presupuesto 0% consumido — y al cargar el primer gasto del mes
 * siguiente el margen se desploma como si la obra se hubiera descontrolado.
 *
 * Los tres campos costo_arranque_* del proyecto son CACHE: se recalculan
 * desde las partidas en la misma transacción de cada alta y baja.
 *
 * NO es una puerta para inventar costos: estados terminales (Finalizada,
 * Cancelada) y comerciales (Borrador, Enviada) lo rechazan, y la acción vive
 * detrás de su propio permiso.
 */
final class RegistrarCostoArranqueService
{
    private const int SCALE = 2;

    private const array ESTADOS_PERMITIDOS = [
        EstadoProyecto::Aprobada,
        EstadoProyecto::EnEjecucion,
        EstadoProyecto::Pausada,
    ];

    public function agregar(
        Proyecto $proyecto,
        RubroCostoArranque $rubro,
        float|string $monto,
        string $descripcion,
        ?Carbon $fecha = null,
        // Auth::id() está tipado int|string|null (soporta claves UUID), así
        // que se acepta tal cual y se normaliza acá en vez de castear en
        // cada llamada.
        int|string|null $registradoPorId = null,
    ): CostoArranqueProyecto {
        $this->validarEstado($proyecto->estado);

        $montoStr = number_format((float) $monto, self::SCALE, '.', '');

        if (bccomp($montoStr, '0', self::SCALE) <= 0) {
            throw DatosEjecucionInvalidosException::costoArranqueNegativo($rubro->getLabel(), $montoStr);
        }

        $texto = trim($descripcion);

        if ($texto === '') {
            throw DatosEjecucionInvalidosException::costoArranqueSinDescripcion();
        }

        $usuarioId = $registradoPorId === null ? null : (int) $registradoPorId;

        return DB::transaction(function () use ($proyecto, $rubro, $montoStr, $texto, $fecha, $usuarioId): CostoArranqueProyecto {
            $fresco = Proyecto::query()->lockForUpdate()->findOrFail($proyecto->id);

            $this->validarEstado($fresco->estado);

            $partida = CostoArranqueProyecto::create([
                'proyecto_id'       => $fresco->id,
                'rubro'             => $rubro->value,
                'monto'             => $montoStr,
                'fecha'             => ($fecha ?? Carbon::today())->copy()->startOfDay(),
                'descripcion'       => $texto,
                'registrado_por_id' => $usuarioId,
            ]);

            $this->recalcularCache($fresco);

            return $partida;
        });
    }

    /**
     * Quita una partida cargada por error. El estado se valida igual que al
     * agregar: una obra finalizada ya no se toca.
     */
    public function eliminar(CostoArranqueProyecto $partida): void
    {
        DB::transaction(function () use ($partida): void {
            $proyecto = Proyecto::query()->lockForUpdate()->findOrFail($partida->proyecto_id);

            $this->validarEstado($proyecto->estado);

            $partida->delete();

            $this->recalcularCache($proyecto);
        });
    }

    /**
     * Recalcula los tres totales por rubro desde las partidas. Idempotente:
     * correrlo dos veces da lo mismo.
     */
    public function recalcularCache(Proyecto $proyecto): Proyecto
    {
        $totales = [];

        foreach (RubroCostoArranque::cases() as $rubro) {
            $suma = (string) CostoArranqueProyecto::query()
                ->where('proyecto_id', $proyecto->id)
                ->where('rubro', $rubro->value)
                ->sum('monto');

            $totales[$rubro->columnaCache()] = number_format((float) $suma, self::SCALE, '.', '');
        }

        $proyecto->forceFill($totales)->save();

        return $proyecto->refresh();
    }

    private function validarEstado(EstadoProyecto $estado): void
    {
        if (! in_array($estado, self::ESTADOS_PERMITIDOS, strict: true)) {
            throw DatosEjecucionInvalidosException::estadoNoPermiteCostoArranque($estado);
        }
    }
}
