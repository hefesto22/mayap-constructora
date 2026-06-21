<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoAsignacion;
use Database\Factories\AsignacionMaquinaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Asignación de una máquina a una obra (proyecto). Congela la tarifa por hora
 * pactada; los partes de trabajo de esta asignación cobran esa tarifa.
 *
 * AUTO-CÓDIGO: ASMQ-{NUMERO_5} global, generado en `creating` con lockForUpdate.
 *
 * @property int $id
 * @property string $codigo
 * @property int $maquina_id
 * @property int $proyecto_id
 * @property string $tarifa_hora_pactada
 * @property Carbon $fecha_inicio
 * @property Carbon|null $fecha_fin
 * @property EstadoAsignacion $estado
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Maquina $maquina
 * @property-read Proyecto $proyecto
 */
class AsignacionMaquina extends Model
{
    /** @use HasFactory<AsignacionMaquinaFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'asignaciones_maquina';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'maquina_id',
        'proyecto_id',
        'tarifa_hora_pactada',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'              => EstadoAsignacion::class,
            'tarifa_hora_pactada' => 'decimal:2',
            'fecha_inicio'        => 'date',
            'fecha_fin'           => 'date',
        ];
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (AsignacionMaquina $asignacion): void {
            if (empty($asignacion->codigo)) {
                $asignacion->codigo = self::generarCodigoSiguiente();
            }
        });
    }

    public static function generarCodigoSiguiente(): string
    {
        $patron = 'ASMQ-';

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
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * @return HasMany<ParteTrabajo, $this>
     */
    public function partes(): HasMany
    {
        return $this->hasMany(ParteTrabajo::class, 'asignacion_maquina_id');
    }

    /**
     * @return HasMany<ConsumoCombustible, $this>
     */
    public function consumos(): HasMany
    {
        return $this->hasMany(ConsumoCombustible::class, 'asignacion_maquina_id');
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('estado', EstadoAsignacion::Activa->value);
    }
}
