<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoReporteFiscal;
use App\Models\ReporteFiscal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

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
            'tipo'              => TipoReporteFiscal::Facturas->value,
            'periodo'           => $periodo,
            'path'              => 'reportes-fiscales/facturas-'.$periodo->format('Y-m').'.pdf',
            'compras_count'     => 0,
            'fotos_count'       => 0,
            'fotos_incluidas'   => null,
            'fotos_purgadas_at' => null,
        ];
    }

    /**
     * Reporte de PAGOS a proveedores del mismo período.
     */
    public function pagos(): static
    {
        return $this->state(function (array $attributes): array {
            $periodo = $attributes['periodo'] ?? today()->subMonthNoOverflow()->startOfMonth();

            return [
                'tipo' => TipoReporteFiscal::Pagos->value,
                'path' => 'reportes-fiscales/pagos-'.Carbon::parse($periodo)->format('Y-m').'.pdf',
            ];
        });
    }
}
