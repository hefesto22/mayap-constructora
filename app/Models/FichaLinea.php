<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoriaBaseLinea;
use App\Enums\CategoriaItem;
use App\Enums\TipoLineaFicha;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\FichaLineaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Línea de composición de una ficha APU.
 *
 * Discriminada por `tipo` (ver enum TipoLineaFicha):
 *
 *  - tipo=Item: referencia un item del catálogo. Activos: item_id,
 *    rendimiento, desperdicio_porcentaje. NULOS: descripcion, porcentaje,
 *    categoria_base, categoria_destino.
 *
 *  - tipo=Porcentaje: línea derivada (ej: HERRAMIENTA MENOR 5% sobre MO).
 *    Activos: descripcion, porcentaje, categoria_base, categoria_destino.
 *    NULOS: item_id, rendimiento, desperdicio_porcentaje.
 *
 * Modelo de captura del rendimiento (decisión Sprint 2 — Sesión 3):
 *  - El campo `rendimiento` siempre almacena el valor EFECTIVO (con la
 *    pérdida ya considerada), con precisión de 6 decimales.
 *  - El campo `desperdicio_porcentaje` es METADATO informativo: documenta
 *    de dónde viene el rendimiento efectivo, NO se aplica al cálculo.
 *  - Fórmula única en runtime: subtotal = rendimiento × precio_unitario.
 *
 * Los CHECK constraints en DB validan la consistencia de cada tipo.
 *
 * El cálculo del subtotal de cada línea NO vive aquí — vive en
 * App\Services\Fichas\CalcularPrecioFichaService. El modelo solo
 * expone los datos. SRP: el modelo es persistencia, el service es
 * lógica de negocio.
 *
 * @property int $id
 * @property int $ficha_id
 * @property TipoLineaFicha $tipo
 * @property int $orden
 * @property int|null $item_id
 * @property string|null $rendimiento
 * @property string|null $desperdicio_porcentaje
 * @property string|null $descripcion
 * @property string|null $porcentaje
 * @property CategoriaBaseLinea|null $categoria_base
 * @property CategoriaItem|null $categoria_destino
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Ficha $ficha
 * @property-read Item|null $item
 */
class FichaLinea extends Model
{
    /** @use HasFactory<FichaLineaFactory> */
    use HasFactory;

    use HasUppercaseAttributes;

    /** @var list<string> */
    protected $fillable = [
        'ficha_id',
        'tipo',
        'orden',
        'item_id',
        'rendimiento',
        'desperdicio_porcentaje',
        'descripcion',
        'porcentaje',
        'categoria_base',
        'categoria_destino',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'                   => TipoLineaFicha::class,
            'orden'                  => 'integer',
            'rendimiento'            => 'decimal:6',
            'desperdicio_porcentaje' => 'decimal:2',
            'porcentaje'             => 'decimal:2',
            'categoria_base'         => CategoriaBaseLinea::class,
            'categoria_destino'      => CategoriaItem::class,
        ];
    }

    // ─── Mutators uppercase ────────────────────────────────────────

    protected function descripcion(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    protected function notas(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<Ficha, $this>
     */
    public function ficha(): BelongsTo
    {
        return $this->belongsTo(Ficha::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    // ─── Helpers de tipo ───────────────────────────────────────────

    public function esItem(): bool
    {
        return $this->tipo === TipoLineaFicha::Item;
    }

    public function esPorcentaje(): bool
    {
        return $this->tipo === TipoLineaFicha::Porcentaje;
    }

    /**
     * Sección visual del reporte donde aparece esta línea.
     *
     * Para tipo=Item: se deriva de la categoría del item referenciado.
     * Para tipo=Porcentaje: se toma del campo `categoria_destino`.
     *
     * Útil para agrupar líneas en el PDF o en la UI por sección
     * (Materiales / Mano de Obra / Herramienta y Equipo / Indirectos).
     */
    public function seccionDelReporte(): ?CategoriaItem
    {
        if ($this->esItem()) {
            return $this->item?->categoria;
        }

        return $this->categoria_destino;
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeDeTipo(Builder $query, TipoLineaFicha $tipo): Builder
    {
        return $query->where('tipo', $tipo->value);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeItems(Builder $query): Builder
    {
        return $query->where('tipo', TipoLineaFicha::Item->value);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePorcentajes(Builder $query): Builder
    {
        return $query->where('tipo', TipoLineaFicha::Porcentaje->value);
    }
}
