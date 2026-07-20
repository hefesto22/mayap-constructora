<?php

declare(strict_types=1);

namespace App\Services\Compras;

use App\Enums\EstadoCompra;
use App\Exceptions\Compras\CompraNoVerificableException;
use App\Models\Compra;
use App\Models\CompraLinea;
use App\Models\User;
use App\Support\Permisos;
use App\Support\Roles;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Verificación de recepción (G2): el punto de llegada cuenta lo que el
 * camión trajo CONTRA lo facturado, línea por línea.
 *
 * ALCANCE por destino de línea (quien compró NO se auto-valida):
 *  - porción a BODEGA  → bodeguero de esa bodega (o con visión total);
 *    gerencia y admin siempre pueden (respaldo).
 *  - porción a OBRA    → encargado de ESA obra; gerencia/admin respaldo.
 *
 * En compras mixtas cada quien verifica su porción; cuando TODAS las
 * líneas están verificadas la compra se CONFIRMA sola: el stock entra por
 * lo RECIBIDO y la CxP se crea por lo FACTURADO (decisión de negocio: la
 * factura es la deuda legal; el faltante queda como reclamo visible).
 */
final readonly class VerificarRecepcionService
{
    private const int SCALE_CANTIDAD = 4;

    public function __construct(
        private ConfirmarCompraService $confirmar,
        private NotificadorCompras $notificador,
        private AlcanceDestinoCompra $alcance,
    ) {}

    /**
     * Captura lo recibido para las líneas dadas. Devuelve el estado final:
     * PorRecibir si aún faltan porciones, Confirmada si todo quedó.
     *
     * @param array<int, string|float|int> $recibidoPorLinea linea_id => cantidad recibida
     */
    public function verificar(Compra $compra, array $recibidoPorLinea, User $verificador): EstadoCompra
    {
        if ($recibidoPorLinea === []) {
            throw CompraNoVerificableException::sinLineasCapturadas($compra->codigo);
        }

        if ($compra->estado !== EstadoCompra::PorRecibir) {
            throw CompraNoVerificableException::estadoInvalido($compra->codigo, $compra->estado);
        }

        return DB::transaction(function () use ($compra, $recibidoPorLinea, $verificador): EstadoCompra {
            $bloqueada = Compra::query()
                ->whereKey($compra->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($bloqueada->estado !== EstadoCompra::PorRecibir) {
                throw CompraNoVerificableException::estadoInvalido($compra->codigo, $bloqueada->estado);
            }

            $compra->load('lineas.material:id,nombre,consumo_inmediato');

            foreach ($recibidoPorLinea as $lineaId => $cantidad) {
                $linea = $compra->lineas->firstWhere('id', $lineaId);

                if (! $linea instanceof CompraLinea) {
                    throw CompraNoVerificableException::lineaAjena($compra->codigo, (int) $lineaId);
                }

                if ($linea->verificada()) {
                    throw CompraNoVerificableException::lineaYaVerificada($compra->codigo, $linea->nombreLinea());
                }

                if (! $this->puedeVerificar($verificador, $compra, $linea)) {
                    throw CompraNoVerificableException::sinAlcance($compra->codigo, $linea->nombreLinea());
                }

                if (! is_numeric($cantidad) || bccomp((string) $cantidad, '0', self::SCALE_CANTIDAD) < 0) {
                    throw CompraNoVerificableException::cantidadInvalida((string) $cantidad);
                }

                $linea->cantidad_recibida = (string) $cantidad;
                $linea->verificada_at = now();
                $linea->verificada_por = $verificador->id;
                $linea->save();
            }

            // ¿Quedó TODO verificado? → confirmar: stock por lo recibido,
            // CxP por lo facturado, requisición despachada por lo recibido.
            if ($compra->lineas->every(fn (CompraLinea $l): bool => $l->verificada())) {
                $this->confirmar->confirmar($compra, $verificador->id);

                $compra->lineas->contains(fn (CompraLinea $l): bool => $l->tieneDiferencia())
                    ? $this->notificador->verificadaConDiferencias($compra, $verificador->id)
                    : $this->notificador->verificadaCompleta($compra, $verificador->id);

                return EstadoCompra::Confirmada;
            }

            return EstadoCompra::PorRecibir;
        });
    }

    /**
     * ¿El usuario puede verificar ESTA línea? Dirigido por PERMISO (se
     * administra desde la pantalla de Roles) + ALCANCE por destino:
     *
     *  - permiso `VerificarRecepcion:Compra` (bodeguero y encargado lo
     *    tienen de fábrica; dárselo a otro rol es decisión del negocio
     *    en la pestaña Personalizados) — y además:
     *  - línea a BODEGA → esa bodega asignada al usuario (o visión total);
     *  - línea a OBRA   → ser encargado de ESA obra.
     *  - gerencia/admin: respaldo universal.
     */
    public function puedeVerificar(User $user, Compra $compra, CompraLinea $linea): bool
    {
        if ($user->hasAnyRole([Roles::GERENCIA, Utils::getSuperAdminName()])) {
            return true;
        }

        if (! $user->can(Permisos::VERIFICAR_RECEPCION_COMPRA)) {
            return false;
        }

        return $this->alcance->alcanza($user, $compra, $linea);
    }

    /**
     * Líneas de la compra que este usuario aún puede verificar (para el
     * modal de la acción: cada quien ve SOLO su porción pendiente).
     *
     * @return Collection<int, CompraLinea>
     */
    public function lineasPendientesPara(User $user, Compra $compra): Collection
    {
        $compra->loadMissing('lineas.material:id,nombre,consumo_inmediato');

        return $compra->lineas
            ->reject(fn (CompraLinea $l): bool => $l->verificada())
            ->filter(fn (CompraLinea $l): bool => $this->puedeVerificar($user, $compra, $l))
            ->values();
    }
}
