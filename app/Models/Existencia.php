<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ExistenciaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Existencia — saldo de stock de un MATERIAL físico en UNA ubicación.
 *
 * Ubicación = bodega física XOR proyecto (CHECK en la tabla garantiza que
 * exactamente una esté poblada). Cada obra es una mini-bodega (ADR-0002 §1).
 *
 * COSTO PROMEDIO PONDERADO (ADR-0002 §3): la fila guarda `cantidad` y
 * `valor_total`; el costo promedio NO se persiste, se deriva en el accessor
 * `costo_promedio` (valor_total / cantidad) para que nunca se desincronice.
 *
 * Esta fila es un SALDO derivado del libro mayor `movimientos_inventario`.
 * Toda escritura pasa por el Service de inventario con lockForUpdate sobre
 * la fila — nunca se modifica directamente desde un Resource.
 *
 * @property int $id
 * @property int $material_id
 * @property int|null $bodega_id
 * @property int|null $proyecto_id
 * @property string $cantidad
 * @property string $valor_total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $costo_promedio
 * @property-read Material $material
 * @property-read Bodega|null $bodega
 * @property-read Proyecto|null $proyecto
 */
class Existencia extends Model
{
    /** @use HasFactory<ExistenciaFactory> */
    use HasFactory;

    protected $table = 'existencias';

    /** @var list<string> */
    protected $fillable = [
        'material_id',
        'bodega_id',
        'proyecto_id',
        'cantidad',
        'valor_total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad'    => 'decimal:4',
            'valor_total' => 'decimal:2',
        ];
    }

    // ─── Accessors ─────────────────────────────────────────────────

    /**
     * Costo promedio ponderado vigente, derivado (no persistido).
     *
     * Devuelve '0.00' cuando no hay existencia para evitar división por
     * cero. Usa bcmath con escala interna 6 y redondeo half-up a 2 al
     * exponer, consistente con el calculador de fichas.
     */
    protected function costoPromedio(): Attribute
    {
        return Attribute::get(function (): string {
            $cantidad = (string) $this->attributes['cantidad'];

            if (bccomp($cantidad, '0', 6) <= 0) {
                return '0.00';
            }

            $promedio = bcdiv((string) $this->attributes['valor_total'], $cantidad, 6);

            // Redondeo half-up a 2 decimales.
            return bcadd($promedio, '0.005', 2);
        });
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * Existencias en bodega física (no en obra).
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeEnBodega(Builder $query): Builder
    {
        return $query->whereNotNull('bodega_id');
    }

    /**
     * Existencias en obra (stock de proyecto).
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeEnObra(Builder $query): Builder
    {
        return $query->whereNotNull('proyecto_id');
    }

    /**
     * Solo existencias con stock disponible (> 0).
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeConStock(Builder $query): Builder
    {
        return $query->where('cantidad', '>', 0);
    }
}
