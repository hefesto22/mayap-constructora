<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Proyecto;
use App\Services\Reportes\ResumenRentaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de UN SOLO USO tras el arreglo del 2026-08-07 (el excedente
 * sobre la jornada se cobraba dos veces).
 *
 * SOLO LEE. No corrige, no ajusta cuentas y no toca la bitácora: lista
 * las rentas a las que ya se les cobró un extra al finalizar, recalcula
 * ese extra con la regla corregida y muestra la diferencia, para decidir
 * a mano si alguna necesita nota de crédito.
 *
 * Se puede borrar cuando la revisión esté hecha.
 */
class AuditarExtrasRenta extends Command
{
    private const int SCALE = 2;

    private const int SCALE_INTERNO = 6;

    protected $signature = 'renta:auditar-extras';

    protected $description = 'Lista las rentas con extra ya cobrado y lo compara contra la regla corregida (solo lectura)';

    public function handle(ResumenRentaService $resumenes): int
    {
        $cobros = DB::table('activity_log')
            ->where('log_name', 'renta')
            ->where('event', 'extra_cobrado')
            ->orderBy('created_at')
            ->get(['subject_id', 'properties', 'created_at']);

        if ($cobros->isEmpty()) {
            $this->info('✓ Ninguna renta llegó a cobrar extra: el arreglo se hizo a tiempo y no hay nada que revisar.');

            return self::SUCCESS;
        }

        $filas = [];
        $totalDiferencia = '0.00';

        foreach ($cobros as $cobro) {
            $proyecto = Proyecto::query()->with('cliente:id,nombre')->find((int) $cobro->subject_id);

            if (! $proyecto instanceof Proyecto) {
                continue;
            }

            /** @var array<string, mixed> $props */
            $props = json_decode((string) $cobro->properties, true) ?: [];
            $cobrado = (string) ($props['extra_total'] ?? '0.00');

            // Viene de un JSON de bitácora, no de una columna con CHECK:
            // una fila corrupta no debe reventar la auditoría.
            if (! is_numeric($cobrado)) {
                $this->warn("{$proyecto->codigo}: el extra registrado en la bitácora no es un número — revisar a mano.");

                continue;
            }

            $correcto = $this->conIsv($proyecto, $resumenes->resumen($proyecto)['total_extra']);
            $diferencia = bcsub($cobrado, $correcto, self::SCALE);
            $totalDiferencia = bcadd($totalDiferencia, $diferencia, self::SCALE);

            $filas[] = [
                $proyecto->codigo,
                $proyecto->cliente->nombre,
                substr((string) $cobro->created_at, 0, 10),
                $cobrado,
                $correcto,
                bccomp($diferencia, '0', self::SCALE) > 0 ? "⚠ {$diferencia}" : $diferencia,
            ];
        }

        $this->newLine();
        $this->table(
            ['Renta', 'Cliente', 'Cerrada', 'Extra cobrado', 'Extra correcto', 'Cobrado de más'],
            $filas,
        );

        if (bccomp($totalDiferencia, '0', self::SCALE) > 0) {
            $this->warn("⚠ Se cobró L {$totalDiferencia} de más en total. Revisar esas cuentas por cobrar a mano.");
        } else {
            $this->info('✓ Ninguna renta quedó cobrada de más.');
        }

        $this->line('Nota: el recálculo usa los partes de trabajo de HOY. Si alguna renta se editó después de cerrarse, verificarla a mano.');

        return self::SUCCESS;
    }

    /**
     * El extra se cobra con ISV (misma fórmula que FinalizarRentaService).
     */
    private function conIsv(Proyecto $proyecto, string $extraSinIsv): string
    {
        if (! $proyecto->aplica_isv || bccomp($extraSinIsv, '0', self::SCALE) <= 0) {
            return bcadd($extraSinIsv, '0.00', self::SCALE);
        }

        $factor = bcadd('1', bcdiv((string) $proyecto->isv_porcentaje, '100', self::SCALE_INTERNO), self::SCALE_INTERNO);

        return bcadd(bcmul($extraSinIsv, $factor, 4), '0.005', self::SCALE);
    }
}
