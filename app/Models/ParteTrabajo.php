<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetodoCapturaHoras;
use App\Enums\ModalidadTrabajo;
use Database\Factories\ParteTrabajoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * Parte de trabajo — el trabajo diario de una máquina en una obra (vía su
 * asignación). Genera el costo: horas × tarifa pactada, congelado al registrar.
 *
 * MODALIDAD (decisión Mauricio 2026-07-20): además de las horas del día
 * (siempre obligatorias — son el costo interno), el parte lleva el dato
 * con el que la máquina trabaja/cobra: `km_recorridos` (pick-ups, suma
 * al kilometraje de la máquina), `viajes` + origen → destino + material
 * (volquetas), o `actividad` (fletes de camiones, texto libre).
 *
 * AUTO-CÓDIGO: PART-{AÑO}-{NUMERO_5} con contador que se reinicia por año,
 * generado en `creating` con lockForUpdate.
 *
 * @property int $id
 * @property string $codigo
 * @property int $asignacion_maquina_id
 * @property Carbon $fecha
 * @property MetodoCapturaHoras $metodo_captura
 * @property ModalidadTrabajo $modalidad
 * @property numeric-string|null $horas_motor
 * @property numeric-string $horas_muertas
 * @property string|null $motivo_idle
 * @property numeric-string $salto_horometro
 * @property string|null $lectura_inicial
 * @property string|null $lectura_final
 * @property string $horas
 * @property string $horas_extra
 * @property string|null $motivo_horas_extra
 * @property string|null $km_recorridos
 * @property int|null $viajes
 * @property string|null $viaje_origen
 * @property string|null $viaje_destino
 * @property string|null $viaje_material
 * @property string|null $actividad
 * @property string $tarifa_aplicada
 * @property string $costo_cache
 * @property string|null $operador
 * @property int|null $operador_id
 * @property-read Operador|null $operadorRegistrado
 * @property string|null $notas
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read AsignacionMaquina $asignacion
 * @property-read User|null $user
 */
class ParteTrabajo extends Model
{
    /** @use HasFactory<ParteTrabajoFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'partes_trabajo';

    /**
     * Default en memoria (espejo del default de la DB) — misma lección
     * que Compra.categoria (2026-07-20).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'modalidad' => 'horas',

        // Espejo de los defaults de la columna: sin esto un parte recién
        // instanciado devuelve null y porcentajeIdle() revienta.
        'horas_muertas'   => '0.00',
        'salto_horometro' => '0.00',
    ];

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'asignacion_maquina_id',
        'fecha',
        'metodo_captura',
        'modalidad',
        'horas_motor',
        'horas_muertas',
        'motivo_idle',
        'salto_horometro',
        'lectura_inicial',
        'lectura_final',
        'horas',
        'horas_extra',
        'motivo_horas_extra',
        'km_recorridos',
        'viajes',
        'viaje_origen',
        'viaje_destino',
        'viaje_material',
        'actividad',
        'tarifa_aplicada',
        'costo_cache',
        'operador',
        'operador_id',
        'notas',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metodo_captura'  => MetodoCapturaHoras::class,
            'modalidad'       => ModalidadTrabajo::class,
            'fecha'           => 'date',
            'horas_motor'     => 'decimal:2',
            'horas_muertas'   => 'decimal:2',
            'salto_horometro' => 'decimal:2',
            'lectura_inicial' => 'decimal:2',
            'lectura_final'   => 'decimal:2',
            'horas'           => 'decimal:2',
            'horas_extra'     => 'decimal:2',
            'km_recorridos'   => 'decimal:2',
            'viajes'          => 'integer',
            'tarifa_aplicada' => 'decimal:2',
            'costo_cache'     => 'decimal:2',
        ];
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    #[Override]
    protected static function booted(): void
    {
        static::creating(static function (ParteTrabajo $parte): void {
            if (empty($parte->codigo)) {
                $anio = ($parte->fecha instanceof Carbon)
                    ? $parte->fecha->year
                    : (int) now()->year;

                $parte->codigo = self::generarCodigoSiguiente($anio);
            }
        });
    }

    /**
     * Genera el siguiente código secuencial PART-{AÑO}-{NUMERO_5}.
     *
     * Concurrencia: lockForUpdate dentro de transacción serializa
     * creaciones simultáneas del mismo año.
     */
    public static function generarCodigoSiguiente(int $anio): string
    {
        $patron = "PART-{$anio}-";

        return DB::transaction(static function () use ($patron): string {
            $ultimo = self::withTrashed()
                ->where('codigo', 'like', $patron.'%')
                ->lockForUpdate()
                ->orderByDesc('codigo')
                ->value('codigo');

            $siguienteNum = 1;

            if ($ultimo !== null) {
                $siguienteNum = (int) substr((string) $ultimo, strlen($patron)) + 1;
            }

            return $patron.str_pad((string) $siguienteNum, 5, '0', STR_PAD_LEFT);
        });
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * El operador del catálogo, cuando se eligió de la lista. El campo
     * `operador` guarda igual el NOMBRE tal cual quedó ese día: si a la
     * persona la renombran después, la historia no se reescribe.
     *
     * @return BelongsTo<Operador, $this>
     */
    public function operadorRegistrado(): BelongsTo
    {
        return $this->belongsTo(Operador::class, 'operador_id');
    }

    /**
     * @return BelongsTo<AsignacionMaquina, $this>
     */
    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(AsignacionMaquina::class, 'asignacion_maquina_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * Partes con horas extra (las que requirieron motivo).
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeConHorasExtra(Builder $query): Builder
    {
        return $query->where('horas_extra', '>', 0);
    }

    /**
     * Porcentaje del tiempo de motor que NO se cobró: calentamiento,
     * esperas, traslados dentro de la obra, almuerzo con el motor prendido.
     * En construcción ronda el 30%; null cuando el parte no trae horómetro.
     */
    public function porcentajeIdle(): ?string
    {
        if ($this->horas_motor === null || bccomp($this->horas_motor, '0', 2) <= 0) {
            return null;
        }

        return bcmul(bcdiv($this->horas_muertas, $this->horas_motor, 6), '100', 2);
    }

    /**
     * ¿Aparecieron horas en el horómetro que nadie reportó? La lectura
     * inicial de este parte no empalmó con la final del anterior.
     */
    public function tieneUsoNoDeclarado(): bool
    {
        return bccomp($this->salto_horometro, '0', 2) > 0;
    }
}
