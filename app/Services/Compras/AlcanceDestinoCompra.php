<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\User;
use App\Services\Inventario\Ubicacion;

/**
 * Alcance de destino: ¿esta línea de compra "le pertenece" al usuario?
 *
 *  - Destino BODEGA → el usuario tiene ESA bodega asignada (o visión
 *    total de bodegas vía permiso).
 *  - Destino OBRA   → el usuario es encargado de ESA obra.
 *
 * ÚNICA fuente de la regla (§8 de instrucciones): la consumen la
 * verificación de recepción, la corrección de conteos, el acta parcial y
 * la captura diferida (que pregunta por la cabecera, porque cuando el
 * detalle todavía no existe no hay línea a la cual preguntarle).
 * Los "pases universales" (gerencia/admin ven todo) NO viven aquí — cada
 * consumidor decide quién los tiene según su contexto.
 */
final class AlcanceDestinoCompra
{
    public function alcanza(User $user, Compra $compra, CompraLinea $linea): bool
    {
        return $this->alcanzaDestino($user, $compra->destinoDeLinea($linea));
    }

    /**
     * La misma regla, pero preguntada contra una ubicación suelta. La usa
     * la captura diferida: la compra se registró sin líneas, así que el
     * único destino que existe todavía es el de la cabecera.
     */
    public function alcanzaDestino(User $user, Ubicacion $destino): bool
    {
        if ($destino->esBodega()) {
            if ($user->puedeVerTodasLasBodegas()) {
                return true;
            }

            return in_array($destino->id, $user->bodegasAsignadasIds(), true);
        }

        return $user->obrasEncargadas()->whereKey($destino->id)->exists();
    }
}
