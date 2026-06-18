<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EstadoProyecto;
use App\Models\Proyecto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job que pasa proyectos `enviada` → `vencida` cuando su `fecha_validez`
 * ya quedó en el pasado.
 *
 * Se programa para correr DIARIO a la 1:00 AM en el scheduler de
 * Laravel (ver `routes/console.php`). También puede dispatcharse
 * manualmente para tests o re-procesos.
 *
 * IDEMPOTENTE: ejecutar dos veces seguidas en el mismo día produce
 * el mismo resultado (la segunda corrida no encuentra más proyectos
 * para vencer).
 *
 * NO toca proyectos en estado Aprobada, Rechazada, Borrador, ni los
 * ya Vencidas — solo los que están Enviada y cuya validez expiró.
 *
 * Loguea en log estándar (channel default) la cantidad de proyectos
 * marcados, para tener trazabilidad operativa del scheduler.
 */
class MarcarProyectosVencidosJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $backoff = 60;

    /**
     * Ejecuta el job. Retorna la cantidad de proyectos marcados como
     * vencidos (útil para tests y monitoring).
     */
    public function handle(): int
    {
        $afectados = Proyecto::query()
            ->enviadosVencidos()
            ->update([
                'estado'     => EstadoProyecto::Vencida->value,
                'updated_at' => now(),
            ]);

        if ($afectados > 0) {
            Log::info('Proyectos vencidos automáticamente', [
                'cantidad' => $afectados,
                'fecha'    => now()->toDateString(),
            ]);
        }

        return $afectados;
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Falló el Job MarcarProyectosVencidosJob', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
