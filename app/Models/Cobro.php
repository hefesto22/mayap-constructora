<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CobroFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cobro — pago parcial o total que un cliente hace contra una cuenta por
 * cobrar. Espejo de Abono.
 *
 * @property int $id
 * @property int $cuenta_por_cobrar_id
 * @property string $monto
 * @property Carbon $fecha
 * @property string|null $metodo
 * @property string|null $referencia
 * @property int|null $user_id
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CuentaPorCobrar $cuentaPorCobrar
 * @property-read User|null $user
 */
class Cobro extends Model
{
    /** @use HasFactory<CobroFactory> */
    use HasFactory;

    protected $table = 'cobros';

    /** @var list<string> */
    protected $fillable = [
        'cuenta_por_cobrar_id',
        'monto',
        'fecha',
        'metodo',
        'referencia',
        'user_id',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha' => 'date',
        ];
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<CuentaPorCobrar, $this>
     */
    public function cuentaPorCobrar(): BelongsTo
    {
        return $this->belongsTo(CuentaPorCobrar::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
