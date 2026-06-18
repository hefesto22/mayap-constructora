<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\ProyectoRenglonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Renglón de un Proyecto/Cotización.
 *
 * Cada renglón es: ficha APU × cantidad = subtotal.
 * Ej: 120.5 M² × L 2,604.37/M² = L 313,826.59.
 *
 * SNAPSHOT DE PRECIO: el campo `precio_unitario_snapshot` se copia
 * del `precio_venta_cache` de la ficha al CREAR el renglón. Las
 * actualizaciones posteriores de la ficha NO modifican este snapshot.
 * Para refrescar todos los snapshots de un proyecto, existe la acción
 * explícita `ActualizarPreciosProyectoService`.
 *
 * CAPÍTULO: campo string libre que agrupa renglones para el reporte.
 * Convenciones recomendadas: "01 PRELIMINARES", "02 CIMENTACIÓN", etc.
 * Filament Resource sugerirá los capítulos previos del proyecto.
 *
 * SUBTOTAL_CACHE: cantidad × precio_unitario_snapshot. CHECK constraint
 * en DB valida que sea coherente (margen 0.02 por redondeo NUMERIC).
 *
 * INVARIANTE DE ZONA: la ficha referenciada DEBE pertenecer a la
 * misma zona que el proyecto. Validado en el Service que crea
 * renglones — no hay CHECK en DB porque requeriría JOIN cross-table.
 *
 * @property int $id
 * @property int $proyecto_id
 * @property int $ficha_id
 * @property int $orden
 * @property string|null $capitulo
 * @property string $cantidad
 * @property string $precio_unitario_snapshot
 * @property string $subtotal_cache
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proyecto $proyecto
 * @property-read Ficha $ficha
 */
class ProyectoRenglon extends Model
{
    /** @use HasFactory<ProyectoRenglonFactory> */
    use HasFactory;

    use HasUppercaseAttributes;

    /**
     * Tabla explícita: la pluralización por defecto de Laravel ("Renglon" →
     * "Renglons") no coincide con nuestra convención en español
     * ("renglones"). Lo declaramos para evitar el "table does not exist".
     */
    protected $table = 'proyecto_renglones';

    /** @var list<string> */
    protected $fillable = [
        'proyecto_id',
        'ficha_id',
        'orden',
        'capitulo',
        'cantidad',
        'precio_unitario_snapshot',
        'subtotal_cache',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden'                    => 'integer',
            'cantidad'                 => 'decimal:4',
            'precio_unitario_snapshot' => 'decimal:2',
            'subtotal_cache'           => 'decimal:2',
        ];
    }

    // ─── Mutators uppercase ────────────────────────────────────────

    protected function capitulo(): Attribute
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
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * @return BelongsTo<Ficha, $this>
     */
    public function ficha(): BelongsTo
    {
        return $this->belongsTo(Ficha::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeDelProyecto(Builder $query, int $proyectoId): Builder
    {
        return $query->where('proyecto_id', $proyectoId);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeDelCapitulo(Builder $query, string $capitulo): Builder
    {
        return $query->where('capitulo', $capitulo);
    }
}
