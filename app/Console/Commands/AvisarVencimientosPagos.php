<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pagos\AvisarVencimientosPagosService;
use Illuminate\Console\Command;

/**
 * Corre una vez al día desde el scheduler (routes/console.php): campanita
 * a gerencia/recepción con las cuentas por PAGAR que vencen en 7 días,
 * 3 días, HOY, o que ya vencieron impagas — las más importantes a pagar
 * según fecha. La lógica vive en AvisarVencimientosPagosService (única
 * puerta, testeable).
 */
class AvisarVencimientosPagos extends Command
{
    protected $signature = 'pagos:avisar-vencimientos';

    protected $description = 'Avisa las cuentas por pagar próximas a vencer (7/3/0 días) y las vencidas';

    public function handle(AvisarVencimientosPagosService $servicio): int
    {
        $avisos = $servicio->avisar();

        $this->info($avisos > 0
            ? "✓ {$avisos} aviso(s) de pagos enviados."
            : 'Sin cuentas por pagar en el radar.');

        return self::SUCCESS;
    }
}
