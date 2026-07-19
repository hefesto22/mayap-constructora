<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cobranza\AvisarVencimientosService;
use Illuminate\Console\Command;

/**
 * Corre una vez al día desde el scheduler (routes/console.php): campanita
 * a gerencia/recepción con las cuentas por cobrar que vencen en 7 días,
 * 3 días, HOY, o que ya vencieron impagas. La lógica vive en
 * AvisarVencimientosService (única puerta, testeable).
 */
class AvisarVencimientosCobranza extends Command
{
    protected $signature = 'cobranza:avisar-vencimientos';

    protected $description = 'Avisa las cuentas por cobrar próximas a vencer (7/3/0 días) y las vencidas';

    public function handle(AvisarVencimientosService $servicio): int
    {
        $avisos = $servicio->avisar();

        $this->info($avisos > 0
            ? "✓ {$avisos} aviso(s) de cobranza enviados."
            : 'Sin cuentas por vencer en el radar.');

        return self::SUCCESS;
    }
}
