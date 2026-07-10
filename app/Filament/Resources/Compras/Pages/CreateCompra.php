<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Pages;

use App\Filament\Resources\Compras\CompraResource;
use App\Models\Requisicion;
use App\Models\RequisicionLinea;
use Filament\Resources\Pages\CreateRecord;

class CreateCompra extends CreateRecord
{
    protected static string $resource = CompraResource::class;

    /**
     * Atajo "Registrar compra" desde una requisición: ?requisicion={id}
     * prellena la compra con los materiales y cantidades FALTANTES
     * (autorizado − ya despachado) y la deja enlazada y con entrega
     * directa a la obra de la requisición. El usuario puede cambiar el
     * destino a bodega si el material sí pasará por inventario.
     */
    protected function afterFill(): void
    {
        $requisicionId = request()->integer('requisicion');

        if ($requisicionId <= 0) {
            return;
        }

        $requisicion = Requisicion::query()
            ->with('lineas.material:id,exento_isv')
            ->find($requisicionId);

        if ($requisicion === null) {
            return;
        }

        $lineas = $requisicion->lineas
            ->map(function (RequisicionLinea $linea): ?array {
                $autorizada = (string) ($linea->cantidad_autorizada ?? $linea->cantidad_solicitada);
                $faltante = bcsub($autorizada, (string) $linea->cantidad_despachada, 4);

                if (bccomp($faltante, '0', 4) <= 0) {
                    return null;
                }

                return [
                    'material_id'    => $linea->material_id,
                    'cantidad'       => $faltante,
                    'costo_unitario' => null,
                    // La herencia del exento vive en afterStateUpdated del
                    // select — el prellenado no lo dispara, se setea aquí.
                    'exento' => (bool) $linea->material->exento_isv,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $this->form->fill([
            ...$this->form->getRawState(),
            'requisicion_id' => $requisicion->id,
            'destino_tipo'   => 'obra',
            'proyecto_id'    => $requisicion->proyecto_id,
            'lineas'         => $lineas,
        ]);
    }
}
