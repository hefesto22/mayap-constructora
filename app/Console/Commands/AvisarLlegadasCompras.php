<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Compras\AvisarLlegadasComprasService;
use Illuminate\Console\Command;

/**
 * Corre una vez al día desde el scheduler (routes/console.php): campanita
 * a recepción/gerencia con los pedidos de compra cuya fecha estimada de
 * llegada ya se alcanzó y siguen sin recibirse. La lógica vive en
 * AvisarLlegadasComprasService (única puerta, testeable).
 */
class AvisarLlegadasCompras extends Command
{
    protected $signature = 'compras:avisar-llegadas';

    protected $description = 'Avisa los pedidos de compra cuya fecha estimada de llegada se alcanzó';

    public function handle(AvisarLlegadasComprasService $servicio): int
    {
        $avisos = $servicio->avisar();

        $this->info($avisos > 0
            ? "✓ {$avisos} aviso(s) de pedidos por llegar enviados."
            : 'Sin pedidos por llegar en el radar.');

        return self::SUCCESS;
    }
}
