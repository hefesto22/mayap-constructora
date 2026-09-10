<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResolucionLinea;
use Database\Factories\RequisicionLineaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * Línea de una requisición — un material con sus cuatro cantidades de
 * trazabilidad (solicitada / autorizada / despachada / recibida).
 *
 * La comparación despachada vs recibida es la que detecta discrepancias.
 * El llenado de cada cantidad lo gobierna el Service al avanzar el estado;
 * este modelo solo persiste y consulta.
 *
 * `resolucion` (Mauricio 2026-09-10) es la respuesta del bodeguero a la
 * única pregunta que le importa a la obra por cada material: ¿me llega o
 * no? Sale de bodega, se compra, o no se consiguió (con su motivo).
 *
 * @property int $id
 * @property int $requisicion_id
 * @property int $material_id
 * @property string $cantidad_solicitada
 * @property string|null $cantidad_autorizada
 * @property string $cantidad_despachada
 * @property string $cantidad_recibida
 * @property ResolucionLinea|null $resolucion
 * @property string|null $resolucion_nota
 * @property Carbon|null $resuelta_at
 * @property int|null $resuelta_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Requisicion $requisicion
 * @property-read Material $material
 */
class RequisicionLinea extends Model
{
    /** @use HasFactory<RequisicionLineaFactory> */
    use HasFactory;

    protected $table = 'requisicion_lineas';

    /** @var list<string> */
    protected $fillable = [
        'requisicion_id',
        'material_id',
        'cantidad_solicitada',
        'cantidad_autorizada',
        'cantidad_despachada',
        'cantidad_recibida',
        'resolucion',
        'resolucion_nota',
        'resuelta_at',
        'resuelta_por',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'cantidad_solicitada' => 'decimal:4',
            'cantidad_autorizada' => 'decimal:4',
            'cantidad_despachada' => 'decimal:4',
            'cantidad_recibida'   => 'decimal:4',
            'resolucion'          => ResolucionLinea::class,
            'resuelta_at'         => 'datetime',
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
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
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

    // ─── Resolución del renglón ────────────────────────────────────

    /**
     * Cantidad vigente del renglón: la autorizada si ya se autorizó, si no
     * la solicitada. Es contra ésta que se mide lo que falta.
     *
     * is_numeric antes de devolverla (regla de la casa): el cast decimal
     * entrega `string` a secas y todo lo que consume esto son bc*, que
     * exigen numeric-string.
     *
     * @return numeric-string
     */
    public function cantidadVigente(): string
    {
        $cantidad = (string) ($this->cantidad_autorizada ?? $this->cantidad_solicitada);

        return is_numeric($cantidad) ? $cantidad : '0';
    }

    /**
     * Lo que todavía no ha salido hacia la obra.
     *
     * @return numeric-string
     */
    public function pendiente(): string
    {
        $despachada = (string) $this->cantidad_despachada;

        if (! is_numeric($despachada)) {
            return $this->cantidadVigente();
        }

        $pendiente = bcsub($this->cantidadVigente(), $despachada, 4);

        return bccomp($pendiente, '0', 4) > 0 ? $pendiente : '0';
    }

    /**
     * ¿Este renglón quedó cerrado sin que llegue nada? Es lo que la obra
     * necesita ver de un vistazo: "ese no me llega".
     */
    public function noLlega(): bool
    {
        return $this->resolucion === ResolucionLinea::NoDisponible;
    }
}
