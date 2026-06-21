<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPlanilla;
use App\Enums\Periodicidad;
use Database\Factories\PlanillaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Planilla — corrida de pago de un período. Agrupa líneas por empleado. Al
 * cerrarse, sus líneas cuentan en el costo de mano de obra de cada obra.
 *
 * AUTO-CÓDIGO: PLA-{AÑO}-{NUMERO_5} con contador que se reinicia por año.
 *
 * @property int $id
 * @property string $codigo
 * @property Periodicidad $periodicidad
 * @property Carbon $fecha_inicio
 * @property Carbon $fecha_fin
 * @property EstadoPlanilla $estado
 * @property string $total_cache
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, PlanillaLinea> $lineas
 */
class Planilla extends Model
{
    /** @use HasFactory<PlanillaFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'planillas';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'periodicidad',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'total_cache',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'periodicidad' => Periodicidad::class,
            'estado'       => EstadoPlanilla::class,
            'fecha_inicio' => 'date',
            'fecha_fin'    => 'date',
            'total_cache'  => 'decimal:2',
        ];
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (Planilla $planilla): void {
            if (empty($planilla->codigo)) {
                $anio = ($planilla->fecha_inicio instanceof Carbon)
                    ? $planilla->fecha_inicio->year
                    : (int) now()->year;

                $planilla->codigo = self::generarCodigoSiguiente($anio);
            }
        });
    }

    public static function generarCodigoSiguiente(int $anio): string
    {
        $patron = "PLA-{$anio}-";

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
     * @return HasMany<PlanillaLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(PlanillaLinea::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeCerradas(Builder $query): Builder
    {
        return $query->where('estado', EstadoPlanilla::Cerrada->value);
    }
}
