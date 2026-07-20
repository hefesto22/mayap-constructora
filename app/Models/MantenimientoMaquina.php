<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoMantenimiento;
use App\Enums\FaseMantenimiento;
use Database\Factories\MantenimientoMaquinaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mantenimiento de máquina — evento de avería/reparación. Enlaza la asignación
 * cortada por la avería y, si hubo, la asignación de la máquina sustituta.
 *
 * Mientras está en proceso lleva una FASE (diagnóstico → sin repuestos →
 * compra de repuestos → reparación) y una BITÁCORA con fecha y hora de cada
 * diagnóstico/avance (decisión Mauricio 2026-07-20). `fecha_estimada_repuestos`
 * dispara la campanita del día en que deberían llegar los repuestos;
 * `aviso_repuestos_at` la hace idempotente (cambiar la fecha la reinicia).
 *
 * AUTO-CÓDIGO: MANT-{AÑO}-{NUMERO_5} con contador que se reinicia por año.
 *
 * @property int $id
 * @property string $codigo
 * @property int $maquina_id
 * @property Carbon $fecha_inicio
 * @property Carbon|null $fecha_fin
 * @property string $motivo
 * @property int|null $asignacion_finalizada_id
 * @property int|null $asignacion_sustituta_id
 * @property EstadoMantenimiento $estado
 * @property FaseMantenimiento $fase
 * @property Carbon|null $fecha_estimada_repuestos
 * @property Carbon|null $aviso_repuestos_at
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Maquina $maquina
 * @property-read AsignacionMaquina|null $asignacionFinalizada
 * @property-read AsignacionMaquina|null $asignacionSustituta
 */
class MantenimientoMaquina extends Model
{
    /** @use HasFactory<MantenimientoMaquinaFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'mantenimientos_maquina';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'maquina_id',
        'fecha_inicio',
        'fecha_fin',
        'motivo',
        'asignacion_finalizada_id',
        'asignacion_sustituta_id',
        'estado',
        'fase',
        'fecha_estimada_repuestos',
        'aviso_repuestos_at',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'                   => EstadoMantenimiento::class,
            'fase'                     => FaseMantenimiento::class,
            'fecha_inicio'             => 'date',
            'fecha_fin'                => 'date',
            'fecha_estimada_repuestos' => 'date',
            'aviso_repuestos_at'       => 'datetime',
        ];
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (MantenimientoMaquina $mantenimiento): void {
            if (empty($mantenimiento->codigo)) {
                $anio = ($mantenimiento->fecha_inicio instanceof Carbon)
                    ? $mantenimiento->fecha_inicio->year
                    : (int) now()->year;

                $mantenimiento->codigo = self::generarCodigoSiguiente($anio);
            }
        });
    }

    /**
     * Genera el siguiente código secuencial MANT-{AÑO}-{NUMERO_5}.
     */
    public static function generarCodigoSiguiente(int $anio): string
    {
        $patron = "MANT-{$anio}-";

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
     * @return BelongsTo<Maquina, $this>
     */
    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class);
    }

    /**
     * @return BelongsTo<AsignacionMaquina, $this>
     */
    public function asignacionFinalizada(): BelongsTo
    {
        return $this->belongsTo(AsignacionMaquina::class, 'asignacion_finalizada_id');
    }

    /**
     * @return BelongsTo<AsignacionMaquina, $this>
     */
    public function asignacionSustituta(): BelongsTo
    {
        return $this->belongsTo(AsignacionMaquina::class, 'asignacion_sustituta_id');
    }

    /**
     * Historial de diagnósticos y avances (fecha y hora en created_at).
     *
     * @return HasMany<BitacoraMantenimiento, $this>
     */
    public function bitacoras(): HasMany
    {
        return $this->hasMany(BitacoraMantenimiento::class, 'mantenimiento_maquina_id');
    }
}
