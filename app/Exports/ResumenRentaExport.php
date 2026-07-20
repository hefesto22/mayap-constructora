<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Proyecto;
use App\Services\Reportes\ResumenRentaService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel del resumen de maquinaria de una renta (decisión Mauricio
 * 2026-07-20): pactado vs real por máquina y unidad de cobro (horas,
 * viajes o km), listo para trabajarlo y reenviarlo. Los números salen
 * de ResumenRentaService (única fuente).
 */
final class ResumenRentaExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    public function __construct(
        private readonly Proyecto $proyecto,
        private readonly ResumenRentaService $servicio,
    ) {}

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        $this->proyecto->loadMissing('cliente:id,nombre');

        $cliente = $this->proyecto->cliente;
        $resumen = $this->servicio->resumen($this->proyecto);

        $fmt = static fn (string $v): string => number_format((float) $v, 2);

        $filas = [
            ['CONSTRUCTORA MAYAP — RESUMEN DE MAQUINARIA (RENTA)'],
            ['Proyecto', $this->proyecto->codigo.' — '.$this->proyecto->nombre],
            ['Cliente', $cliente !== null ? $cliente->nombre : '—'],
            ['Generado', now()->format('d/m/Y H:i')],
            [],
            [
                'Máquina',
                'Unidad',
                'Cotizado (líneas)',
                'Tarifa (L)',
                'Pactado',
                'Real (partes)',
                'Diferencia',
                'Pactado (L)',
                'Extra facturable (L)',
            ],
        ];

        foreach ($resumen['filas'] as $fila) {
            $filas[] = [
                $fila['maquina'],
                $fila['unidad'],
                $fila['detalle'],
                $fmt($fila['tarifa']),
                $fmt($fila['pactado_cant']),
                $fmt($fila['real_cant']),
                $fmt($fila['diferencia']),
                $fmt($fila['pactado']),
                $fmt($fila['extra']),
            ];
        }

        $filas[] = [
            'TOTALES',
            '',
            '',
            '',
            '',
            '',
            '',
            $fmt($resumen['total_pactado']),
            $fmt($resumen['total_extra']),
        ];

        $filas[] = [];
        $filas[] = ['Regla de cobro: se factura lo pactado como mínimo; el extra sale del trabajo real (horas, viajes o km de los partes) que supera lo cotizado (sin ISV — el ISV se aplica en la cuenta por cobrar).'];

        return $filas;
    }

    /**
     * Negrita para el título, la fila de encabezados y los totales.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $filaTotales = 7 + count($this->servicio->resumen($this->proyecto)['filas']);

        return [
            1            => ['font' => ['bold' => true, 'size' => 13]],
            6            => ['font' => ['bold' => true]],
            $filaTotales => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return mb_substr('Renta '.$this->proyecto->codigo, 0, 31);
    }
}
