<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\DestinoSalidaMaquina;
use App\Exceptions\Maquinaria\AgendaInvalidaException;
use App\Models\AgendaMaquina;
use App\Models\Maquina;
use App\Models\User;
use App\Support\Roles;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Facades\DB;

/**
 * Confirmación de llegada Y salida (decisión Mauricio 2026-07-15): el
 * encargado — que es quien está parado en la obra — marca con un click
 * en el calendario que la máquina YA llegó, y después que YA terminó.
 * Queda quién y a qué hora en ambos extremos, y el rol maquinaria
 * recibe la campanita (cierra el ciclo del aviso "llega en 1 hora").
 *
 * Reglas:
 *  - Confirma el encargado de ESA obra (o maquinaria/gerencia de respaldo).
 *  - Solo el día del agendado en adelante — no se confirma el futuro.
 *  - La LLEGADA es una sola vez: es un hecho, no un toggle.
 *  - El CIERRE DEL DÍA se repite todos los días mientras la máquina
 *    siga en la obra; solo el día que se va cierra la estadía.
 *  - UNA OBRA A LA VEZ: mientras la máquina tenga una llegada confirmada
 *    sin salida, ninguna otra obra puede confirmar su llegada — la
 *    máquina no está en dos lugares al mismo tiempo.
 */
final readonly class ConfirmarLlegadaService
{
    public function __construct(private NotificadorMaquinaria $notificador) {}

    /**
     * @param string|null $horometroLlegada Lectura del horómetro con la que
     *                                      la máquina llega a la obra. Es el
     *                                      punto de partida de la estadía:
     *                                      sin fecha de fin, comparar esta
     *                                      lectura contra la de salida es la
     *                                      única forma de saber cuánto
     *                                      trabajó la máquina EN ESA OBRA.
     */
    public function confirmar(AgendaMaquina $agendado, User $user, ?string $horometroLlegada = null): AgendaMaquina
    {
        // load() y NO loadMissing(), y con horometro_actual en el select: la
        // tabla precarga 'maquina' con pocas columnas y el horómetro llegaba
        // null, reventando el bccomp de más abajo (5.ª vez la misma trampa —
        // regla de la casa).
        $agendado->load(['maquina:id,nombre,horometro_actual,modalidad_trabajo', 'proyecto:id,nombre']);

        if ($agendado->llegada_confirmada_at !== null) {
            throw AgendaInvalidaException::llegadaYaConfirmada(
                $agendado->llegada_confirmada_at->format('d/m/Y g:i A')
            );
        }

        if ($agendado->fecha->isFuture()) {
            throw AgendaInvalidaException::llegadaAntesDeTiempo($agendado->fecha->format('d/m/Y'));
        }

        if (! $this->puedeConfirmar($agendado, $user)) {
            throw AgendaInvalidaException::confirmaSoloLaObra();
        }

        // La máquina no está en dos lugares a la vez: si sigue "adentro"
        // de otra obra (llegó y nadie confirmó que terminó), primero se
        // cierra allá.
        $abierto = $this->compromisoAbierto($agendado);

        if ($abierto instanceof AgendaMaquina) {
            throw AgendaInvalidaException::sigueTrabajandoEnOtraObra(
                $agendado->maquina->nombre,
                $abierto->proyecto->nombre,
                $abierto->llegada_confirmada_at?->format('g:i A') ?? '',
            );
        }

        // El horómetro no retrocede: una lectura menor a la que ya trae la
        // máquina es un error de digitación, no un dato.
        $lectura = null;

        if ($horometroLlegada !== null && $horometroLlegada !== '') {
            $lectura = number_format((float) $horometroLlegada, 2, '.', '');
            $actual = $agendado->maquina->horometro_actual ?? '0.00';

            if (bccomp($lectura, $actual, 2) < 0) {
                throw AgendaInvalidaException::horometroRetrocede($lectura, $actual);
            }
        }

        DB::transaction(function () use ($agendado, $user, $lectura): void {
            $agendado->forceFill([
                'llegada_confirmada_at'  => now(),
                'llegada_confirmada_por' => $user->id,
                'horometro_llegada'      => $lectura,
            ])->save();

            // La máquina arranca la estadía desde esa lectura, así el parte
            // del primer día no abre con un horómetro viejo.
            if ($lectura !== null) {
                Maquina::query()->whereKey($agendado->maquina_id)->update(['horometro_actual' => $lectura]);
            }
        });

        // Maquinaria se entera de que su máquina ya está en la obra.
        $this->notificador->llegadaConfirmada($agendado, $user);

        return $agendado->refresh();
    }

    /**
     * CIERRE DEL DÍA (decisión Mauricio 2026-09-05).
     *
     * Al final de cada jornada el encargado registra con cuánto quedó el
     * horómetro y dice qué pasó con la máquina. De esa respuesta depende
     * TODO lo demás:
     *
     *  - "Se quedó en la obra" (lo normal): el día cierra, la lectura
     *    queda guardada y la estadía sigue ABIERTA — la máquina amanece
     *    ahí y nadie más puede recibirla. No hay campanita: maquinaria no
     *    necesita enterarse de que la máquina sigue donde estaba.
     *  - Bodega, otra obra o taller: la estadía se CIERRA, la máquina
     *    queda libre para la siguiente obra y maquinaria recibe el aviso.
     *
     * Sin destino explícito se cierra (es como lo llamaba el código
     * anterior y como lo siguen usando las pruebas del ciclo).
     *
     * @param string|null $horometroSalida Lectura con la que cierra el
     *                                     día. Contra la de llegada da
     *                                     cuánto trabajó EN ESA OBRA.
     */
    public function confirmarSalida(
        AgendaMaquina $agendado,
        User $user,
        ?string $horometroSalida = null,
        ?DestinoSalidaMaquina $destino = null,
    ): AgendaMaquina {
        // load() y NO loadMissing(), y con horometro_actual en el select: la
        // tabla precarga 'maquina' con pocas columnas y el horómetro llegaba
        // null, reventando el bccomp de más abajo (5.ª vez la misma trampa —
        // regla de la casa).
        $agendado->load(['maquina:id,nombre,horometro_actual,modalidad_trabajo', 'proyecto:id,nombre']);

        if ($agendado->llegada_confirmada_at === null) {
            throw AgendaInvalidaException::salidaSinLlegada();
        }

        if ($agendado->salida_confirmada_at !== null) {
            throw AgendaInvalidaException::salidaYaConfirmada(
                $agendado->salida_confirmada_at->format('d/m/Y g:i A')
            );
        }

        if (! $this->puedeConfirmar($agendado, $user)) {
            throw AgendaInvalidaException::confirmaSoloLaObra();
        }

        $cierraEstadia = ! $destino instanceof DestinoSalidaMaquina || $destino->cierraEstadia();

        // El horómetro no retrocede. El piso es la lectura MÁS ALTA que ya
        // conocemos: en una estadía de varios días el horómetro de llegada
        // es el del primer día, y el de la máquina trae el cierre de ayer.
        $lectura = null;

        if ($horometroSalida !== null && $horometroSalida !== '') {
            $lectura = number_format((float) $horometroSalida, 2, '.', '');
            $piso = $agendado->horometro_llegada ?? '0.00';
            $actual = $agendado->maquina->horometro_actual ?? '0.00';

            if (bccomp($actual, $piso, 2) > 0) {
                $piso = $actual;
            }

            if (bccomp($lectura, $piso, 2) < 0) {
                throw AgendaInvalidaException::horometroRetrocede($lectura, $piso);
            }
        }

        DB::transaction(function () use ($agendado, $user, $lectura, $destino, $cierraEstadia): void {
            $campos = [
                // Si el cierre del día no trae lectura (máquinas que no
                // usan horómetro), se conserva la última que sí hubo.
                'horometro_salida' => $lectura ?? $agendado->horometro_salida,
                'destino_salida'   => $destino?->value,
            ];

            if ($cierraEstadia) {
                $campos['salida_confirmada_at'] = now();
                $campos['salida_confirmada_por'] = $user->id;
            }

            $agendado->forceFill($campos)->save();

            if ($lectura !== null) {
                Maquina::query()->whereKey($agendado->maquina_id)->update(['horometro_actual' => $lectura]);
            }
        });

        // La campanita es para avisar que la máquina QUEDÓ LIBRE. Si se
        // quedó en la obra no hay nada que avisar (y una campanita diaria
        // "sigue donde estaba" es ruido que enseña a ignorar el resto).
        if ($cierraEstadia) {
            $this->notificador->salidaConfirmada($agendado, $user);
        }

        return $agendado->refresh();
    }

    /**
     * El compromiso ABIERTO de esta máquina en OTRA obra: llegada
     * confirmada sin salida. Null = la máquina está libre.
     *
     * SIN filtro de fecha (2026-09-05): con la estadía abierta el
     * compromiso vive en la fila del día que LLEGÓ, y una máquina que
     * lleva tres días en una obra tiene que seguir bloqueando a las
     * demás — no solo el día de su agendado.
     */
    public function compromisoAbierto(AgendaMaquina $agendado): ?AgendaMaquina
    {
        return AgendaMaquina::query()
            ->with('proyecto:id,nombre')
            ->where('maquina_id', $agendado->maquina_id)
            ->whereKeyNot($agendado->id)
            ->whereNotNull('llegada_confirmada_at')
            ->whereNull('salida_confirmada_at')
            ->orderBy('fecha')
            ->first();
    }

    /**
     * ¿Este usuario puede confirmar ESTE agendado? El encargado de la
     * obra es el caso normal; maquinaria/gerencia/super de respaldo.
     *
     * RECEPCIÓN NO entra, aunque sea la comodín de maquinaria: confirmar
     * una llegada no es una tarea de oficina, es de PRESENCIA — solo
     * quien está en el sitio puede decir que la máquina llegó. Por eso
     * la campanita "confirma cuando llegue" va únicamente a los
     * encargados de la obra (NotificadorMaquinaria::maquinaPorLlegar) y
     * el calendario le responde a los demás "esta llegada la marca quien
     * está en el sitio" (decisión 2026-08-07, revisada y ratificada el
     * 2026-08-17).
     */
    public function puedeConfirmar(AgendaMaquina $agendado, User $user): bool
    {
        if ($agendado->proyecto->esEncargado($user)) {
            return true;
        }

        return $user->hasAnyRole([Roles::MAQUINARIA, Roles::GERENCIA, Utils::getSuperAdminName()]);
    }
}
