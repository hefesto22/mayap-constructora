<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\AlertaMantenimiento;
use App\Models\PlanMantenimiento;

/**
 * Pasada diaria de mantenimiento preventivo: revisa los planes activos
 * de máquinas activas, calcula la alerta de cada uno (horas / km /
 * días — lo que llegue primero) y manda la campanita cuando un plan
 * cruza a PRÓXIMO o a VENCIDO.
 *
 * Idempotente por diseño (mismo patrón que cobranza):
 * `ultimo_aviso_estado` guarda el nivel ya avisado y solo se ESCALA
 * (próximo → vencido) — correr el comando dos veces el mismo día no
 * duplica campanitas. Registrar el cambio limpia la marca y el ciclo
 * vuelve a empezar.
 *
 * Si un plan saltó directo a vencido (máquina que trabajó mucho entre
 * pasadas), avisa UNA vez con el nivel real — no dos avisos en cadena.
 */
final class AvisarMantenimientosService
{
    public function __construct(private readonly NotificadorMantenimiento $notificador) {}

    /**
     * @return int Cuántos avisos se enviaron en esta pasada.
     */
    public function avisar(): int
    {
        $planes = PlanMantenimiento::query()
            ->activos()
            ->whereHas('maquina', fn ($q) => $q->where('activo', true))
            ->with('maquina')
            ->get();

        $avisos = 0;

        foreach ($planes as $plan) {
            $estado = $plan->estadoAlerta();

            if (! $this->tocaAvisar($plan, $estado)) {
                continue;
            }

            match ($estado) {
                AlertaMantenimiento::Vencido => $this->notificador->mantenimientoVencido($plan),
                default                      => $this->notificador->mantenimientoProximo($plan),
            };

            $plan->forceFill(['ultimo_aviso_estado' => $estado->value])->save();
            $avisos++;
        }

        return $avisos;
    }

    /**
     * ¿Este nivel aún no se avisó? Solo se escala hacia adelante:
     * (nada) → proximo → vencido, sin repetir ni retroceder.
     */
    private function tocaAvisar(PlanMantenimiento $plan, AlertaMantenimiento $estado): bool
    {
        if ($estado === AlertaMantenimiento::AlDia) {
            return false;
        }

        $yaAvisado = $plan->ultimo_aviso_estado === null
            ? null
            : AlertaMantenimiento::from($plan->ultimo_aviso_estado);

        return $yaAvisado === null
            || $estado->severidad() > $yaAvisado->severidad();
    }
}
