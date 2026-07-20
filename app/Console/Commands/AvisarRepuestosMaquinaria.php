<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Maquinaria\AvisarRepuestosService;
use Illuminate\Console\Command;

/**
 * Corre una vez al día desde el scheduler (routes/console.php): campanita
 * a gerencia/maquinaria/recepción con los mantenimientos cuyos repuestos
 * deberían estar llegando (fecha estimada de recepción alcanzada). La
 * lógica vive en AvisarRepuestosService (única puerta, testeable).
 */
class AvisarRepuestosMaquinaria extends Command
{
    protected $signature = 'maquinaria:avisar-repuestos';

    protected $description = 'Avisa los mantenimientos cuyos repuestos deberían estar llegando (fecha estimada alcanzada)';

    public function handle(AvisarRepuestosService $servicio): int
    {
        $avisos = $servicio->avisar();

        $this->info($avisos > 0
            ? "✓ {$avisos} aviso(s) de repuestos enviados."
            : 'Sin repuestos por llegar en el radar.');

        return self::SUCCESS;
    }
}
