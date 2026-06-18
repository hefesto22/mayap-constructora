<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoRequisicion;
use App\Models\Requisicion;
use App\Models\RequisicionTransicion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequisicionTransicion>
 */
class RequisicionTransicionFactory extends Factory
{
    protected $model = RequisicionTransicion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requisicion_id' => Requisicion::factory(),
            'estado_origen'  => null,
            'estado_destino' => EstadoRequisicion::Solicitada->value,
            'user_id'        => null,
            'nota'           => null,
        ];
    }
}
