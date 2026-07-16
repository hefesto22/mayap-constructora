<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Maquinaria\AvisarLlegadasService;
use Illuminate\Console\Command;

/**
 * Corre cada 10 minutos desde el scheduler (routes/console.php): campanita
 * a los encargados cuando su máquina agendada llega dentro de la próxima
 * hora. La lógica vive en AvisarLlegadasService (única puerta, testeable).
 */
class AvisarLlegadasMaquinaria extends Command
{
    protected $signature = 'maquinaria:avisar-llegadas';

    protected $description = 'Avisa a los encargados de obra las máquinas que llegan dentro de la próxima hora';

    public function handle(AvisarLlegadasService $servicio): int
    {
        $avisos = $servicio->avisar();

        $this->info($avisos > 0
            ? "✓ {$avisos} aviso(s) de llegada enviados."
            : 'Sin llegadas en la próxima hora.');

        return self::SUCCESS;
    }
}
