<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\User;

/**
 * Alcance de destino: ¿esta línea de compra "le pertenece" al usuario?
 *
 *  - Línea a BODEGA → el usuario tiene ESA bodega asignada (o visión
 *    total de bodegas vía permiso).
 *  - Línea a OBRA   → el usuario es encargado de ESA obra.
 *
 * ÚNICA fuente de la regla (§8 de instrucciones): la consumen la
 * verificación de recepción, la corrección de conteos y el acta parcial.
 * Los "pases universales" (gerencia/admin ven todo) NO viven aquí — cada
 * consumidor decide quién los tiene según su contexto.
 */
final class AlcanceDestinoCompra
{
    public function alcanza(User $user, Compra $compra, CompraLinea $linea): bool
    {
        $destino = $compra->destinoDeLinea($linea);

        if ($destino->esBodega()) {
            return $user->puedeVerTodasLasBodegas()
                || in_array($destino->id, $user->bodegasAsignadasIds(), true);
        }

        return $user->obrasEncargadas()->whereKey($destino->id)->exists();
    }
}
