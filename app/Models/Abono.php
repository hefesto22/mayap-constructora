<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AbonoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Abono — pago parcial o total contra una cuenta por pagar.
 *
 * @property int $id
 * @property int $cuenta_por_pagar_id
 * @property string $monto
 * @property Carbon $fecha
 * @property string|null $metodo
 * @property string|null $referencia
 * @property int|null $user_id
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CuentaPorPagar $cuentaPorPagar
 */
class Abono extends Model
{
    /** @use HasFactory<AbonoFactory> */
    use HasFactory;

    protected $table = 'abonos';

    /** @var list<string> */
    protected $fillable = [
        'cuenta_por_pagar_id',
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
     * @return BelongsTo<CuentaPorPagar, $this>
     */
    public function cuentaPorPagar(): BelongsTo
    {
        return $this->belongsTo(CuentaPorPagar::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
