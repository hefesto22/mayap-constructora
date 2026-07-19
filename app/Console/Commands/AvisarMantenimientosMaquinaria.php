<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Maquinaria\AvisarMantenimientosService;
use Illuminate\Console\Command;

/**
 * Corre una vez al día desde el scheduler (routes/console.php):
 * campanita a gerencia/maquinaria con los planes de mantenimiento
 * preventivo que están PRÓXIMOS (90% del intervalo) o VENCIDOS.
 * La lógica vive en AvisarMantenimientosService (única puerta,
 * testeable).
 */
class AvisarMantenimientosMaquinaria extends Command
{
    protected $signature = 'maquinaria:avisar-mantenimientos';

    protected $description = 'Avisa los mantenimientos preventivos próximos (90% del intervalo) y vencidos';

    public function handle(AvisarMantenimientosService $servicio): int
    {
        $avisos = $servicio->avisar();

        $this->info($avisos > 0
            ? "✓ {$avisos} aviso(s) de mantenimiento enviados."
            : 'Sin mantenimientos próximos ni vencidos.');

        return self::SUCCESS;
    }
}
