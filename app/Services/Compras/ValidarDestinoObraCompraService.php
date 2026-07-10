<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoProyecto;
use App\Exceptions\Compras\CompraNoConfirmableException;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\Proyecto;
use App\Models\User;
use App\Services\Requisiciones\PresupuestoMaterialesProyectoService;
use App\Support\Permisos;

/**
 * Valida los destinos a OBRA de una compra antes de registrarla:
 *
 *  1. La obra debe estar VIVA (En ejecución o Pausada) — a una obra
 *     terminada, cancelada o sin iniciar no se le imputa costo. Bloqueo
 *     duro, sin excepciones.
 *  2. El material debe estar PRESUPUESTADO en las fichas de esa obra.
 *     Los imprevistos reales existen (se quebró una manguera): quien tenga
 *     el permiso "Comprar fuera de presupuesto" (gerencia por defecto,
 *     pestaña Personalizados de Roles) puede saltarse esta regla — y la
 *     compra queda visible como "fuera de presupuesto" en el Control de
 *     materiales del proyecto.
 *
 * Se valida al REGISTRAR (fail fast): si la obra cambia de estado mientras
 * el camión viaja, la verificación no se bloquea — el material ya salió.
 */
final readonly class ValidarDestinoObraCompraService
{
    public function __construct(
        private PresupuestoMaterialesProyectoService $presupuesto,
    ) {}

    public function validar(Compra $compra, ?User $actor): void
    {
        $compra->loadMissing('lineas.material:id,nombre');

        /** @var array<int, list<CompraLinea>> $porObra */
        $porObra = [];

        foreach ($compra->lineas as $linea) {
            $destino = $compra->destinoDeLinea($linea);

            if ($destino->esObra()) {
                $porObra[$destino->id][] = $linea;
            }
        }

        if ($porObra === []) {
            return;
        }

        $obras = Proyecto::query()
            ->whereIn('id', array_keys($porObra))
            ->get()
            ->keyBy('id');

        $puedeFueraDePresupuesto = $actor?->can(Permisos::COMPRAR_FUERA_DE_PRESUPUESTO) ?? false;

        foreach ($porObra as $obraId => $lineas) {
            /** @var Proyecto $obra */
            $obra = $obras->get($obraId);

            if (! in_array($obra->estado, [EstadoProyecto::EnEjecucion, EstadoProyecto::Pausada], strict: true)) {
                throw CompraNoConfirmableException::obraNoRecibeMaterial(
                    $compra->codigo,
                    $obra->nombre,
                    $obra->estado->getLabel(),
                );
            }

            if ($puedeFueraDePresupuesto) {
                continue;
            }

            foreach ($lineas as $linea) {
                $presupuesto = $this->presupuesto->paraMaterial($obraId, $linea->material_id);

                if ($presupuesto === null || bccomp($presupuesto->presupuestado, '0', 4) <= 0) {
                    throw CompraNoConfirmableException::materialNoPresupuestado(
                        $compra->codigo,
                        $linea->material->nombre,
                        $obra->nombre,
                    );
                }
            }
        }
    }
}
