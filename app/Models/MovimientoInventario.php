<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoMovimientoInventario;
use Database\Factories\MovimientoInventarioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * MovimientoInventario — entrada del libro mayor INMUTABLE de inventario.
 *
 * Cada fila explica un cambio de stock: entrada por compra, salida a obra,
 * traslado, consumo, devolución o ajuste. Las `existencias` son el saldo
 * derivado; estos movimientos son la bitácora auditable que lo justifica.
 * NUNCA se editan ni borran — una corrección es un movimiento inverso nuevo.
 *
 * Por eso este modelo es de solo-escritura-única: lo crea el Service de
 * inventario dentro de la misma transacción que actualiza la existencia.
 * No lleva LogsActivity porque ES, en sí mismo, el registro de auditoría.
 *
 * Origen y destino se modelan como pares bodega_x/proyecto_x nullable; el
 * tipo (enum) determina qué lados aplican y los CHECK de la tabla validan
 * que cada lado activo tenga a lo sumo una ubicación.
 *
 * @property int $id
 * @property TipoMovimientoInventario $tipo
 * @property int $material_id
 * @property int|null $bodega_origen_id
 * @property int|null $proyecto_origen_id
 * @property int|null $bodega_destino_id
 * @property int|null $proyecto_destino_id
 * @property string $cantidad
 * @property string $costo_unitario
 * @property string $valor_total
 * @property string|null $motivo
 * @property string|null $referencia_type
 * @property int|null $referencia_id
 * @property int|null $user_id
 * @property Carbon $fecha
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Material $material
 */
class MovimientoInventario extends Model
{
    /** @use HasFactory<MovimientoInventarioFactory> */
    use HasFactory;

    protected $table = 'movimientos_inventario';

    /** @var list<string> */
    protected $fillable = [
        'tipo',
        'material_id',
        'bodega_origen_id',
        'proyecto_origen_id',
        'bodega_destino_id',
        'proyecto_destino_id',
        'cantidad',
        'costo_unitario',
        'valor_total',
        'motivo',
        'referencia_type',
        'referencia_id',
        'user_id',
        'fecha',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'           => TipoMovimientoInventario::class,
            'cantidad'       => 'decimal:4',
            'costo_unitario' => 'decimal:4',
            'valor_total'    => 'decimal:2',
            'fecha'          => 'date',
        ];
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
    public function bodegaOrigen(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_origen_id');
    }

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyectoOrigen(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_origen_id');
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodegaDestino(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_destino_id');
    }

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyectoDestino(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_destino_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Documento de negocio que originó el movimiento (requisición, compra).
     *
     * @return MorphTo<Model, $this>
     */
    public function referencia(): MorphTo
    {
        return $this->morphTo();
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeDeMaterial(Builder $query, int $materialId): Builder
    {
        return $query->where('material_id', $materialId);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeDeTipo(Builder $query, TipoMovimientoInventario $tipo): Builder
    {
        return $query->where('tipo', $tipo->value);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeEntreFechas(Builder $query, ?string $desde, ?string $hasta): Builder
    {
        return $query
            ->when($desde, static fn (Builder $q, string $d): Builder => $q->whereDate('fecha', '>=', $d))
            ->when($hasta, static fn (Builder $q, string $h): Builder => $q->whereDate('fecha', '<=', $h));
    }

    /**
     * Limita los movimientos a los que tocan una bodega visible del usuario,
     * más los de obra (consistente con la visibilidad de stock en obra de
     * Fase 2). Quien tiene `VerTodasLasBodegas:Bodega` ve todo.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeVisibleParaUsuario(Builder $query, User $usuario): Builder
    {
        if ($usuario->puedeVerTodasLasBodegas()) {
            return $query;
        }

        $bodegas = $usuario->bodegasAsignadasIds();

        return $query->where(function (Builder $q) use ($bodegas): void {
            $q->whereIn('bodega_origen_id', $bodegas)
                ->orWhereIn('bodega_destino_id', $bodegas)
                ->orWhereNotNull('proyecto_origen_id')
                ->orWhereNotNull('proyecto_destino_id');
        });
    }
}
