<?php

declare(strict_types=1);

namespace App\Services\Maquinaria;

use App\Enums\MetodoCapturaHoras;
use App\Enums\ModalidadTrabajo;
use App\Exceptions\Maquinaria\ParteInvalidoException;
use App\Models\AsignacionMaquina;
use App\Models\Maquina;
use App\Models\Operador;
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

    /**
     * Tope de tiempo muerto que no necesita explicación. La industria de la
     * construcción promedia ~30% de idle sobre el tiempo de motor, así que
     * por debajo de 35% es un día normal y pedir motivo sería ruido.
     */
    private const string PORCENTAJE_IDLE_TOLERADO = '35';

    private const int SCALE_HORAS = 2;

    private const int SCALE_MONTO = 2;

    /**
     * Registra un parte capturado por horómetro.
     *
     * El horómetro mide MOTOR ENCENDIDO; `$horasCobradas` son las horas
     * PRODUCTIVAS que se le facturan a la obra. Normalmente son distintas: la
     * diferencia es tiempo muerto (calentamiento, esperas, traslados dentro
     * de la obra, almuerzo con el motor prendido), que en construcción ronda
     * el 30% del tiempo de motor. Si no se pasan horas cobradas, se cobra el
     * delta completo del horómetro.
     */
    public function registrarPorHorometro(
        AsignacionMaquina $asignacion,
        string $lecturaFinal,
        ?string $lecturaInicial = null,
        ?string $horasCobradas = null,
        ?string $motivoIdle = null,
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
        ?int $operadorId = null,
    ): ParteTrabajo {
        $asignacion->loadMissing('maquina');

        $inicial = $lecturaInicial ?? (string) $asignacion->maquina->horometro_actual;

        if (bccomp($lecturaFinal, $inicial, self::SCALE_HORAS) < 0) {
            throw ParteInvalidoException::lecturaFinalMenorQueInicial($lecturaFinal, $inicial);
        }

        $horasMotor = bcsub($lecturaFinal, $inicial, self::SCALE_HORAS);
        $horas = $horasCobradas ?? $horasMotor;

        // Cobrar más horas de las que el motor estuvo encendido es facturar
        // aire. Al revés sí se puede: eso es tiempo muerto.
        if (bccomp($horas, $horasMotor, self::SCALE_HORAS) > 0) {
            throw ParteInvalidoException::cobraMasQueElMotor($horas, $horasMotor);
        }

        return $this->persistir(
            asignacion: $asignacion,
            metodo: MetodoCapturaHoras::Horometro,
            horas: $horas,
            horasMotor: $horasMotor,
            motivoIdle: $motivoIdle,
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
            operadorId: $operadorId,
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
        ?int $operadorId = null,
    ): ParteTrabajo {
        return $this->persistir(
            asignacion: $asignacion,
            metodo: MetodoCapturaHoras::Manual,
            horas: $horas,
            horasMotor: null,
            motivoIdle: null,
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
            operadorId: $operadorId,
        );
    }

    /**
     * Tarifa pactada y cantidad a cobrar según la modalidad del parte.
     *
     * El flete se cobra por MONTO FIJO (un flete por parte), así que su
     * cantidad es 1: el precio pactado ya contempla el recorrido completo.
     *
     * @return array{0: string, 1: string} [tarifa, cantidad]
     */
    private function baseDeCobro(
        AsignacionMaquina $asignacion,
        ModalidadTrabajo $modalidad,
        string $horas,
        ?string $kmRecorridos,
        ?int $viajes,
    ): array {
        [$tarifa, $cantidad] = match ($modalidad) {
            ModalidadTrabajo::Horas       => [$asignacion->tarifa_hora_pactada, $horas],
            ModalidadTrabajo::Kilometraje => [$asignacion->tarifa_km_pactada, $kmRecorridos ?? '0'],
            ModalidadTrabajo::Viajes      => [$asignacion->tarifa_viaje_pactada, (string) ($viajes ?? 0)],
            ModalidadTrabajo::Flete       => [$asignacion->tarifa_flete_pactada, '1'],
        };

        // Sin tarifa pactada para su dimensión el parte costearía 0 en
        // silencio y la obra quedaría con el margen inflado. Mejor frenar.
        if ($tarifa === null) {
            throw ParteInvalidoException::sinTarifaPactada($modalidad, $asignacion->codigo);
        }

        return [(string) $tarifa, $cantidad];
    }

    private function persistir(
        AsignacionMaquina $asignacion,
        MetodoCapturaHoras $metodo,
        string $horas,
        ?string $horasMotor,
        ?string $motivoIdle,
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
        ?int $operadorId,
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

        return DB::transaction(function () use ($asignacion, $metodo, $horas, $horasMotor, $motivoIdle, $lecturaInicial, $lecturaFinal, $fecha, $motivoHorasExtra, $operador, $userId, $notas, $modalidad, $kmRecorridos, $viajes, $viajeOrigen, $viajeDestino, $viajeMaterial, $actividad, $operadorId): ParteTrabajo {
            // Bloquea la asignación y la máquina para serializar lecturas.
            $asignacionBloqueada = AsignacionMaquina::query()
                ->whereKey($asignacion->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Si vino del catálogo y nadie escribió un nombre, el nombre
            // de ese día es el que el catálogo tiene AHORA.
            $nombreOperador = $operadorId !== null
                ? Operador::query()->whereKey($operadorId)->value('nombre')
                : null;

            if (! $asignacionBloqueada->estado->esActiva()) {
                throw ParteInvalidoException::asignacionNoActiva($asignacionBloqueada->codigo);
            }

            $maquina = Maquina::query()
                ->whereKey($asignacionBloqueada->maquina_id)
                ->lockForUpdate()
                ->firstOrFail();

            // El horómetro nunca retrocede.
            $saltoHorometro = '0.00';

            if ($metodo->usaHorometro() && $lecturaFinal !== null) {
                $horometroActual = (string) $maquina->horometro_actual;

                if (bccomp($lecturaFinal, $horometroActual, self::SCALE_HORAS) < 0) {
                    throw ParteInvalidoException::lecturaRetrocede($lecturaFinal, $horometroActual);
                }

                // USO NO DECLARADO: si el parte ABRE por encima de donde quedó
                // el horómetro de la máquina, esas horas las trabajó alguien y
                // nadie las reportó — fin de semana, trabajo por fuera, o un
                // parte que se olvidaron de cargar. No se bloquea (el dato ya
                // ocurrió), se deja asentado para que se pueda auditar.
                if ($lecturaInicial !== null
                    && bccomp($lecturaInicial, $horometroActual, self::SCALE_HORAS) > 0) {
                    $saltoHorometro = bcsub($lecturaInicial, $horometroActual, self::SCALE_HORAS);
                }
            }

            // TIEMPO MUERTO: lo que corrió el motor y no se cobró. No es un
            // error — es calentamiento, esperas y traslados dentro de la obra.
            // Solo se pide explicación cuando se pasa de lo tolerado.
            $horasMuertas = '0.00';

            if ($horasMotor !== null) {
                $horasMuertas = bcsub($horasMotor, $horas, self::SCALE_HORAS);

                if (bccomp($horasMotor, '0', self::SCALE_HORAS) > 0) {
                    $pctIdle = bcmul(bcdiv($horasMuertas, $horasMotor, 6), '100', self::SCALE_HORAS);

                    if (bccomp($pctIdle, self::PORCENTAJE_IDLE_TOLERADO, self::SCALE_HORAS) > 0
                        && ($motivoIdle === null || trim($motivoIdle) === '')) {
                        throw ParteInvalidoException::sinMotivoIdle($pctIdle, $horasMuertas);
                    }
                }
            }

            // HORAS EXTRA: ya no existen en obra propia. La "jornada de la
            // máquina" no era un dato real — hay días de 4 horas y días de 12
            // (Mauricio, 2026-09-04) — así que exigir motivo por pasarse de 8
            // pedía explicación casi todos los días y el campo terminaba
            // lleno de "trabajo normal". El excedente sobre lo contratado lo
            // mide la RENTA con su propia unidad; acá la señal útil es el
            // tiempo muerto, que sí compara contra algo real: el horómetro.
            $horasExtra = '0.00';

            // COSTO SEGÚN LA MODALIDAD: una volqueta se cobra por viajes, una
            // pick-up por km, un flete por su monto. Antes todo se costeaba
            // por horas y el margen de las obras con volquetas salía mal.
            [$tarifa, $cantidad] = $this->baseDeCobro(
                $asignacionBloqueada,
                $modalidad,
                $horas,
                $kmRecorridos,
                $viajes,
            );

            $costo = $this->bcround(bcmul($cantidad, $tarifa, self::SCALE_INTERNO), self::SCALE_MONTO);

            $parte = ParteTrabajo::create([
                'asignacion_maquina_id' => $asignacionBloqueada->id,
                'fecha'                 => $fecha ?? now()->toDateString(),
                'metodo_captura'        => $metodo,
                'modalidad'             => $modalidad,
                'lectura_inicial'       => $lecturaInicial,
                'lectura_final'         => $lecturaFinal,
                'horas'                 => $horas,
                'horas_motor'           => $horasMotor,
                'horas_muertas'         => $horasMuertas,
                'salto_horometro'       => $saltoHorometro,
                'horas_extra'           => $horasExtra,
                'motivo_horas_extra'    => $motivoHorasExtra,
                'motivo_idle'           => $motivoIdle === null || trim($motivoIdle) === '' ? null : trim($motivoIdle),
                'km_recorridos'         => $modalidad === ModalidadTrabajo::Kilometraje ? $kmRecorridos : null,
                'viajes'                => $modalidad === ModalidadTrabajo::Viajes ? $viajes : null,
                'viaje_origen'          => $modalidad === ModalidadTrabajo::Viajes ? $viajeOrigen : null,
                'viaje_destino'         => $modalidad === ModalidadTrabajo::Viajes ? $viajeDestino : null,
                'viaje_material'        => $modalidad === ModalidadTrabajo::Viajes ? $viajeMaterial : null,
                'actividad'             => $modalidad === ModalidadTrabajo::Flete ? $actividad : null,
                'tarifa_aplicada'       => $tarifa,
                'costo_cache'           => $costo,
                // El catálogo manda el vínculo; el texto queda como
                // FOTO del nombre de ese día (renombrar a la persona no
                // reescribe la historia — 2026-09-05).
                'operador'    => $operador ?? $nombreOperador,
                'operador_id' => $operadorId,
                'notas'       => $notas,
                'user_id'     => $userId,
            ]);

            // El horómetro de la máquina avanza con la lectura final.
            // number_format: la columna es decimal:2 y el modelo la declara
            // numeric-string, así que se normaliza al asignar en vez de
            // meterle una cadena cualquiera.
            if ($metodo->usaHorometro() && $lecturaFinal !== null) {
                $maquina->horometro_actual = number_format((float) $lecturaFinal, self::SCALE_HORAS, '.', '');
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
