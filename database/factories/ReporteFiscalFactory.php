<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReporteFiscal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReporteFiscal>
 */
class ReporteFiscalFactory extends Factory
{
    protected $model = ReporteFiscal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodo = today()->subMonthNoOverflow()->startOfMonth();

        return [
            'periodo'           => $periodo,
            'path'              => 'reportes-fiscales/facturas-'.$periodo->format('Y-m').'.pdf',
            'compras_count'     => 0,
            'fotos_count'       => 0,
            'fotos_incluidas'   => null,
            'fotos_purgadas_at' => null,
        ];
    }
}
