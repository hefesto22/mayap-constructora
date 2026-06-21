<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MetodoCapturaHoras;
use Database\Factories\ParteTrabajoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Parte de trabajo — horas trabajadas de una máquina en una obra (vía su
 * asignación). Genera el cobro: horas × tarifa pactada, congelado al registrar.
 *
 * AUTO-CÓDIGO: PART-{AÑO}-{NUMERO_5} con contador que se reinicia por año,
 * generado en `creating` con lockForUpdate.
 *
 * @property int $id
 * @property string $codigo
 * @property int $asignacion_maquina_id
 * @property Carbon $fecha
 * @property MetodoCapturaHoras $metodo_captura
 * @property string|null $lectura_inicial
 * @property string|null $lectura_final
 * @property string $horas
 * @property string $horas_extra
 * @property string|null $motivo_horas_extra
 * @property string $tarifa_hora_aplicada
 * @property string $costo_cache
 * @property string|null $operador
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

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'asignacion_maquina_id',
        'fecha',
        'metodo_captura',
        'lectura_inicial',
        'lectura_final',
        'horas',
        'horas_extra',
        'motivo_horas_extra',
        'tarifa_hora_aplicada',
        'costo_cache',
        'operador',
        'notas',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metodo_captura'       => MetodoCapturaHoras::class,
            'fecha'                => 'date',
            'lectura_inicial'      => 'decimal:2',
            'lectura_final'        => 'decimal:2',
            'horas'                => 'decimal:2',
            'horas_extra'          => 'decimal:2',
            'tarifa_hora_aplicada' => 'decimal:2',
            'costo_cache'          => 'decimal:2',
        ];
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

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
}
