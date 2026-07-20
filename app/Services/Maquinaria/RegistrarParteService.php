<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\MetodoCapturaHoras;
use App\Enums\ModalidadTrabajo;
use App\Exceptions\Maquinaria\ParteInvalidoException;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Models\ParteTrabajo;
use Illuminate\Support\Facades\DB;

/**
 * Registra partes de trabajo de una máquina asignada a una obra: calcula
 * horas, horas extra (sobre la jornada de la máquina) y el costo (horas ×
 * tarifa pactada de la asignación). Es la única puerta para crear partes.
 *
 * Captura por horómetro: valida que el reloj no retroceda y lo actualiza.
 * Captura manual: horas directas (respaldo cuando el horómetro falla), sin
 * tocar el horómetro de la máquina.
 *
 * MODALIDADES (decisión Mauricio 2026-07-20): el parte puede además
 * registrar kilómetros (pick-ups — suman al kilometraje de la máquina y
 * alimentan su mantenimiento por km), viajes con origen → destino
 * (volquetas) o la actividad del flete (camiones). Las horas del día
 * siguen siendo obligatorias: son el costo interno de la obra.
 *
 * Todo bajo transacción con lock sobre la máquina para serializar lecturas
 * concurrentes del horómetro.
 */
final class RegistrarParteService
{
    private const int SCALE_INTERNO = 12;

    private const int SCALE_HORAS = 2;

    private const int SCALE_MONTO = 2;

    /**
     * Registra un parte capturado por horómetro. Si no se pasa la lectura
     * inicial, usa el horómetro actual de la máquina.
     */
    public function registrarPorHorometro(
        AsignacionMaquina $asignacion,
        string $lecturaFinal,
        ?string $lecturaInicial = null,
        ?string $fecha = null,
        ?string $motivoHorasExtra = null,
        ?string $operador = null,
        ?int $userId = null,
        ?string $notas = null,
        ModalidadTrabajo $modalidad = ModalidadTrabajo::Horas,
        ?string $kmRecorridos = null,
        ?int $viajes = null,
        ?string $viajeOrigen = null,
        ?string $viajeDestino = null,
        ?string $viajeMaterial = null,
        ?string $actividad = null,
    ): ParteTrabajo {
        $asignacion->loadMissing('maquina');

        $inicial = $lecturaInicial ?? (string) $asignacion->maquina->horometro_actual;

        if (bccomp($lecturaFinal, $inicial, self::SCALE_HORAS) < 0) {
            throw ParteInvalidoException::lecturaFinalMenorQueInicial($lecturaFinal, $inicial);
        }

        $horas = bcsub($lecturaFinal, $inicial, self::SCALE_HORAS);

        return $this->persistir(
            asignacion: $asignacion,
            metodo: MetodoCapturaHoras::Horometro,
            horas: $horas,
            lecturaInicial: $inicial,
            lecturaFinal: $lecturaFinal,
            fecha: $fecha,
            motivoHorasExtra: $motivoHorasExtra,
            operador: $operador,
            userId: $userId,
            notas: $notas,
            modalidad: $modalidad,
            kmRecorridos: $kmRecorridos,
            viajes: $viajes,
            viajeOrigen: $viajeOrigen,
            viajeDestino: $viajeDestino,
            viajeMaterial: $viajeMaterial,
            actividad: $actividad,
        );
    }

    /**
     * Registra un parte con horas capturadas a mano (horómetro fuera de
     * servicio, o modalidades sin horómetro: km, viajes, flete). No
     * modifica el horómetro de la máquina.
     */
    public function registrarManual(
        AsignacionMaquina $asignacion,
        string $horas,
        ?string $fecha = null,
        ?string $motivoHorasExtra = null,
        ?string $operador = null,
        ?int $userId = null,
        ?string $notas = null,
        ModalidadTrabajo $modalidad = ModalidadTrabajo::Horas,
        ?string $kmRecorridos = null,
        ?int $viajes = null,
        ?string $viajeOrigen = null,
        ?string $viajeDestino = null,
        ?string $viajeMaterial = null,
        ?string $actividad = null,
    ): ParteTrabajo {
        return $this->persistir(
            asignacion: $asignacion,
            metodo: MetodoCapturaHoras::Manual,
            horas: $horas,
            lecturaInicial: null,
            lecturaFinal: null,
            fecha: $fecha,
            motivoHorasExtra: $motivoHorasExtra,
            operador: $operador,
            userId: $userId,
            notas: $notas,
            modalidad: $modalidad,
            kmRecorridos: $kmRecorridos,
            viajes: $viajes,
            viajeOrigen: $viajeOrigen,
            viajeDestino: $viajeDestino,
            viajeMaterial: $viajeMaterial,
            actividad: $actividad,
        );
    }

    private function persistir(
        AsignacionMaquina $asignacion,
        MetodoCapturaHoras $metodo,
        string $horas,
        ?string $lecturaInicial,
        ?string $lecturaFinal,
        ?string $fecha,
        ?string $motivoHorasExtra,
        ?string $operador,
        ?int $userId,
        ?string $notas,
        ModalidadTrabajo $modalidad,
        ?string $kmRecorridos,
        ?int $viajes,
        ?string $viajeOrigen,
        ?string $viajeDestino,
        ?string $viajeMaterial,
        ?string $actividad,
    ): ParteTrabajo {
        if (bccomp($horas, '0', self::SCALE_HORAS) <= 0) {
            throw ParteInvalidoException::horasInvalidas($horas);
        }

        // Cada modalidad exige SU dato (la regla dura vive también en
        // los CHECKs de la tabla).
        if ($modalidad === ModalidadTrabajo::Kilometraje
            && ($kmRecorridos === null || bccomp($kmRecorridos, '0', self::SCALE_HORAS) <= 0)) {
            throw ParteInvalidoException::kmInvalidos($kmRecorridos);
        }

        if ($modalidad === ModalidadTrabajo::Viajes && ($viajes === null || $viajes <= 0)) {
            throw ParteInvalidoException::viajesInvalidos($viajes);
        }

        if ($modalidad === ModalidadTrabajo::Flete && ($actividad === null || trim($actividad) === '')) {
            throw ParteInvalidoException::sinActividad();
        }

        return DB::transaction(function () use ($asignacion, $metodo, $horas, $lecturaInicial, $lecturaFinal, $fecha, $motivoHorasExtra, $operador, $userId, $notas, $modalidad, $kmRecorridos, $viajes, $viajeOrigen, $viajeDestino, $viajeMaterial, $actividad): ParteTrabajo {
            // Bloquea la asignación y la máquina para serializar lecturas.
            $asignacionBloqueada = AsignacionMaquina::query()
                ->whereKey($asignacion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $asignacionBloqueada->estado->esActiva()) {
                throw ParteInvalidoException::asignacionNoActiva($asignacionBloqueada->codigo);
            }

            $maquina = Maquina::query()
                ->whereKey($asignacionBloqueada->maquina_id)
                ->lockForUpdate()
                ->firstOrFail();

            // El horómetro nunca retrocede.
            if ($metodo->usaHorometro() && $lecturaFinal !== null) {
                $horometroActual = (string) $maquina->horometro_actual;

                if (bccomp($lecturaFinal, $horometroActual, self::SCALE_HORAS) < 0) {
                    throw ParteInvalidoException::lecturaRetrocede($lecturaFinal, $horometroActual);
                }
            }

            // Horas extra = lo que excede la jornada estándar de la máquina.
            $jornada = (string) $maquina->jornada_horas;
            $horasExtra = bccomp($horas, $jornada, self::SCALE_HORAS) > 0
                ? bcsub($horas, $jornada, self::SCALE_HORAS)
                : '0.00';

            if (bccomp($horasExtra, '0', self::SCALE_HORAS) > 0
                && ($motivoHorasExtra === null || trim($motivoHorasExtra) === '')) {
                throw ParteInvalidoException::sinMotivoHorasExtra($horasExtra);
            }

            // Costo = horas × tarifa pactada (snapshot).
            $tarifa = (string) $asignacionBloqueada->tarifa_hora_pactada;
            $costo = $this->bcround(bcmul($horas, $tarifa, self::SCALE_INTERNO), self::SCALE_MONTO);

            $parte = ParteTrabajo::create([
                'asignacion_maquina_id' => $asignacionBloqueada->id,
                'fecha'                 => $fecha ?? now()->toDateString(),
                'metodo_captura'        => $metodo,
                'modalidad'             => $modalidad,
                'lectura_inicial'       => $lecturaInicial,
                'lectura_final'         => $lecturaFinal,
                'horas'                 => $horas,
                'horas_extra'           => $horasExtra,
                'motivo_horas_extra'    => $motivoHorasExtra,
                'km_recorridos'         => $modalidad === ModalidadTrabajo::Kilometraje ? $kmRecorridos : null,
                'viajes'                => $modalidad === ModalidadTrabajo::Viajes ? $viajes : null,
                'viaje_origen'          => $modalidad === ModalidadTrabajo::Viajes ? $viajeOrigen : null,
                'viaje_destino'         => $modalidad === ModalidadTrabajo::Viajes ? $viajeDestino : null,
                'viaje_material'        => $modalidad === ModalidadTrabajo::Viajes ? $viajeMaterial : null,
                'actividad'             => $modalidad === ModalidadTrabajo::Flete ? $actividad : null,
                'tarifa_hora_aplicada'  => $tarifa,
                'costo_cache'           => $costo,
                'operador'              => $operador,
                'notas'                 => $notas,
                'user_id'               => $userId,
            ]);

            // El horómetro de la máquina avanza con la lectura final.
            if ($metodo->usaHorometro() && $lecturaFinal !== null) {
                $maquina->horometro_actual = $lecturaFinal;
            }

            // Los km del día SUMAN al kilometraje de la máquina — así el
            // mantenimiento preventivo por km se alimenta solo.
            if ($modalidad === ModalidadTrabajo::Kilometraje && $kmRecorridos !== null) {
                $maquina->kilometraje_actual = bcadd(
                    (string) ($maquina->kilometraje_actual ?? '0'),
                    $kmRecorridos,
                    self::SCALE_HORAS,
                );
            }

            if ($maquina->isDirty()) {
                $maquina->save();
            }

            return $parte;
        });
    }

    private function bcround(string $value, int $scale): string
    {
        $factor = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($value, '0', self::SCALE_INTERNO) >= 0) {
            return bcadd($value, $factor, $scale);
        }

        return bcsub($value, $factor, $scale);
    }
}
