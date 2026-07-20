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
 * LÍNEA LIBRE (decisión Mauricio 2026-07-20): en las compras de taller/
 * equipo/oficina no hay catálogo — `material_id` queda NULL y `descripcion`
 * lleva el texto escrito a mano. Las líneas libres NO tocan inventario
 * (gasto directo). CHECK en DB: material O descripción, al menos uno.
 *
 * DESTINO POR LÍNEA: `bodega_id` XOR `proyecto_id` (a lo sumo uno, CHECK en
 * DB). Ambos null = la línea hereda el destino de la cabecera. Permite
 * compras mixtas: una factura con líneas a bodega y líneas directo a obra.
 *
 * @property int $id
 * @property int $compra_id
 * @property int|null $material_id
 * @property string|null $descripcion
 * @property int|null $bodega_id
 * @property int|null $proyecto_id
 * @property string $cantidad
 * @property string $costo_unitario
 * @property bool $exento
 * @property string $subtotal
 * @property string|null $cantidad_recibida
 * @property Carbon|null $verificada_at
 * @property int|null $verificada_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Compra $compra
 * @property-read Material|null $material
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
        'descripcion',
        'bodega_id',
        'proyecto_id',
        'cantidad',
        'costo_unitario',
        'exento',
        'subtotal',
        'cantidad_recibida',
        'verificada_at',
        'verificada_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad'          => 'decimal:4',
            'costo_unitario'    => 'decimal:4',
            'exento'            => 'boolean',
            'subtotal'          => 'decimal:2',
            'cantidad_recibida' => 'decimal:4',
            'verificada_at'     => 'datetime',
        ];
    }

    // ─── Verificación de recepción (G2) ────────────────────────────

    /**
     * ¿La línea ya fue verificada en su punto de llegada?
     */
    public function verificada(): bool
    {
        return $this->verificada_at !== null;
    }

    /**
     * ¿Lo recibido NO coincide con lo facturado? (reclamo al proveedor)
     */
    public function tieneDiferencia(): bool
    {
        return $this->verificada()
            && bccomp((string) $this->cantidad_recibida, (string) $this->cantidad, 4) !== 0;
    }

    /**
     * Cantidad que realmente entra al inventario: lo recibido si la línea
     * fue verificada; lo facturado si la compra siguió el flujo directo.
     */
    public function cantidadEfectiva(): string
    {
        return (string) ($this->cantidad_recibida ?? $this->cantidad);
    }

    /**
     * Nombre para mostrar: el material del catálogo o, en líneas libres,
     * la descripción escrita a mano. ÚNICA fuente para avisos y PDFs.
     */
    public function nombreLinea(): string
    {
        $material = $this->material;

        if ($material !== null) {
            return $material->nombre;
        }

        return (string) $this->descripcion;
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

    /**
     * Usuario que contó/verificó esta línea al llegar (G2).
     *
     * @return BelongsTo<User, $this>
     */
    public function verificadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verificada_por');
    }

    /**
     * ¿La línea define su propio destino (no hereda el de la cabecera)?
     */
    public function tieneDestinoPropio(): bool
    {
        return $this->bodega_id !== null || $this->proyecto_id !== null;
    }
}
