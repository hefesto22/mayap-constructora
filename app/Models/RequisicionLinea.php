<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RequisicionLineaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Línea de una requisición — un item con sus cuatro cantidades de
 * trazabilidad (solicitada / autorizada / despachada / recibida).
 *
 * La comparación despachada vs recibida es la que detecta discrepancias.
 * El llenado de cada cantidad lo gobierna el Service al avanzar el estado;
 * este modelo solo persiste y consulta.
 *
 * @property int $id
 * @property int $requisicion_id
 * @property int $item_id
 * @property string $cantidad_solicitada
 * @property string|null $cantidad_autorizada
 * @property string $cantidad_despachada
 * @property string $cantidad_recibida
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Requisicion $requisicion
 * @property-read Item $item
 */
class RequisicionLinea extends Model
{
    /** @use HasFactory<RequisicionLineaFactory> */
    use HasFactory;

    protected $table = 'requisicion_lineas';

    /** @var list<string> */
    protected $fillable = [
        'requisicion_id',
        'item_id',
        'cantidad_solicitada',
        'cantidad_autorizada',
        'cantidad_despachada',
        'cantidad_recibida',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad_solicitada' => 'decimal:4',
            'cantidad_autorizada' => 'decimal:4',
            'cantidad_despachada' => 'decimal:4',
            'cantidad_recibida'   => 'decimal:4',
        ];
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<Requisicion, $this>
     */
    public function requisicion(): BelongsTo
    {
        return $this->belongsTo(Requisicion::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * Líneas donde lo despachado no coincide con lo recibido.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeConDiscrepancia(Builder $query): Builder
    {
        return $query->whereColumn('cantidad_despachada', '!=', 'cantidad_recibida');
    }
}
