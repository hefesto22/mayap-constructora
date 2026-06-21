<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsumoCombustibleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consumo de combustible de una máquina en una obra (vía su asignación).
 * Genera un costo (litros × precio) que se carga a la obra.
 *
 * AUTO-CÓDIGO: COMB-{AÑO}-{NUMERO_5} con contador que se reinicia por año.
 *
 * @property int $id
 * @property string $codigo
 * @property int $asignacion_maquina_id
 * @property Carbon $fecha
 * @property string $cantidad_litros
 * @property string $precio_litro
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
class ConsumoCombustible extends Model
{
    /** @use HasFactory<ConsumoCombustibleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'consumos_combustible';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'asignacion_maquina_id',
        'fecha',
        'cantidad_litros',
        'precio_litro',
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
            'fecha'           => 'date',
            'cantidad_litros' => 'decimal:2',
            'precio_litro'    => 'decimal:4',
            'costo_cache'     => 'decimal:2',
        ];
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (ConsumoCombustible $consumo): void {
            if (empty($consumo->codigo)) {
                $anio = ($consumo->fecha instanceof Carbon)
                    ? $consumo->fecha->year
                    : (int) now()->year;

                $consumo->codigo = self::generarCodigoSiguiente($anio);
            }
        });
    }

    /**
     * Genera el siguiente código secuencial COMB-{AÑO}-{NUMERO_5}.
     *
     * Concurrencia: lockForUpdate dentro de transacción serializa
     * creaciones simultáneas del mismo año.
     */
    public static function generarCodigoSiguiente(int $anio): string
    {
        $patron = "COMB-{$anio}-";

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
}
