<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoCuentaPorPagar;
use Database\Factories\CuentaPorPagarFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Cuenta por pagar — saldo de una compra a crédito. Se genera al confirmar
 * la compra y se reduce con abonos (AbonarService gestiona saldo y estado).
 *
 * `ultimo_aviso_dias` es el escalón de aviso ya notificado (7/3/0/-1),
 * espejo de la cobranza: AvisarVencimientosPagosService solo lo avanza.
 *
 * @property int $id
 * @property int $compra_id
 * @property int $proveedor_id
 * @property string $monto_original
 * @property string $saldo
 * @property Carbon $fecha_emision
 * @property Carbon $fecha_vencimiento
 * @property EstadoCuentaPorPagar $estado
 * @property int|null $ultimo_aviso_dias
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Compra $compra
 * @property-read Proveedor $proveedor
 */
class CuentaPorPagar extends Model
{
    /** @use HasFactory<CuentaPorPagarFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'cuentas_por_pagar';

    /** @var list<string> */
    protected $fillable = [
        'compra_id',
        'proveedor_id',
        'monto_original',
        'saldo',
        'fecha_emision',
        'fecha_vencimiento',
        'estado',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'            => EstadoCuentaPorPagar::class,
            'monto_original'    => 'decimal:2',
            'saldo'             => 'decimal:2',
            'fecha_emision'     => 'date',
            'fecha_vencimiento' => 'date',
        ];
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<Compra, $this>
     */
    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    /**
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * @return HasMany<Abono, $this>
     */
    public function abonos(): HasMany
    {
        return $this->hasMany(Abono::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * Cuentas con saldo pendiente (no pagadas).
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('saldo', '>', 0);
    }

    /**
     * Con saldo y vencimiento dentro de los próximos $dias (incluye HOY).
     * Alimenta la pestaña "Por vencer" y el radar de avisos de pago.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePorVencer(Builder $query, int $dias = 7): Builder
    {
        return $query->where('saldo', '>', 0)
            ->whereDate('fecha_vencimiento', '>=', today())
            ->whereDate('fecha_vencimiento', '<=', today()->addDays($dias));
    }

    /**
     * Con saldo y fecha máxima de pago YA pasada: lo que urge pagar.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeVencidas(Builder $query): Builder
    {
        return $query->where('saldo', '>', 0)
            ->whereDate('fecha_vencimiento', '<', today());
    }
}
