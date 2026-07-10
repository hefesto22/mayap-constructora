<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Pages;

use App\Filament\Resources\Compras\CompraResource;
use App\Models\Requisicion;
use Filament\Resources\Pages\CreateRecord;

class CreateCompra extends CreateRecord
{
    protected static string $resource = CompraResource::class;

    /**
     * Atajo "Registrar compra" desde una requisición: ?requisicion={id}
     * enlaza la compra y la deja con entrega directa a la obra de la
     * requisición. Las LÍNEAS prellenadas viven en el default() del
     * repeater (CompraForm::lineasDesdeRequisicion) — rellenarlas aquí
     * con form->fill() rompía la hidratación del repeater de tabla.
     */
    protected function afterFill(): void
    {
        $requisicionId = request()->integer('requisicion');

        if ($requisicionId <= 0) {
            return;
        }

        $requisicion = Requisicion::query()->find($requisicionId);

        if ($requisicion === null) {
            return;
        }

        $this->form->fill([
            ...$this->form->getRawState(),
            'requisicion_id' => $requisicion->id,
            'destino_tipo'   => 'obra',
            'proyecto_id'    => $requisicion->proyecto_id,
        ]);
    }
}
