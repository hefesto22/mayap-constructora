<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompraLineaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Línea de una compra — un material con cantidad y costo unitario NETO (el que
 * capitaliza a inventario al confirmar la compra).
 *
 * @property int $id
 * @property int $compra_id
 * @property int $material_id
 * @property string $cantidad
 * @property string $costo_unitario
 * @property string $subtotal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Compra $compra
 * @property-read Material $material
 */
class CompraLinea extends Model
{
    /** @use HasFactory<CompraLineaFactory> */
    use HasFactory;

    protected $table = 'compra_lineas';

    /** @var list<string> */
    protected $fillable = [
        'compra_id',
        'material_id',
        'cantidad',
        'costo_unitario',
        'subtotal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad'       => 'decimal:4',
            'costo_unitario' => 'decimal:4',
            'subtotal'       => 'decimal:2',
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
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
