<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoCuentaPorCobrar;
use Database\Factories\CuentaPorCobrarFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cuenta por cobrar — lo que un cliente le debe a MAYAP. Se reduce con cobros
 * (CobrarService gestiona saldo y estado). Espejo de CuentaPorPagar.
 *
 * AUTO-CÓDIGO: CXC-{AÑO}-{NUMERO_5} con contador que se reinicia por año.
 *
 * @property int $id
 * @property string $codigo
 * @property int $cliente_id
 * @property int|null $proyecto_id
 * @property string|null $concepto
 * @property string $monto_original
 * @property string $saldo
 * @property Carbon $fecha_emision
 * @property Carbon $fecha_vencimiento
 * @property EstadoCuentaPorCobrar $estado
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Cliente $cliente
 * @property-read Proyecto|null $proyecto
 */
class CuentaPorCobrar extends Model
{
    /** @use HasFactory<CuentaPorCobrarFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'cuentas_por_cobrar';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'cliente_id',
        'proyecto_id',
        'concepto',
        'monto_original',
        'saldo',
        'fecha_emision',
        'fecha_vencimiento',
        'estado',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'            => EstadoCuentaPorCobrar::class,
            'monto_original'    => 'decimal:2',
            'saldo'             => 'decimal:2',
            'fecha_emision'     => 'date',
            'fecha_vencimiento' => 'date',
        ];
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (CuentaPorCobrar $cuenta): void {
            if (empty($cuenta->codigo)) {
                $anio = ($cuenta->fecha_emision instanceof Carbon)
                    ? $cuenta->fecha_emision->year
                    : (int) now()->year;

                $cuenta->codigo = self::generarCodigoSiguiente($anio);
            }
        });
    }

    public static function generarCodigoSiguiente(int $anio): string
    {
        $patron = "CXC-{$anio}-";

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
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * @return HasMany<Cobro, $this>
     */
    public function cobros(): HasMany
    {
        return $this->hasMany(Cobro::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('saldo', '>', 0);
    }
}
