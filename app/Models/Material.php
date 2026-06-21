<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoriaItem;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Material — el RECURSO FÍSICO canónico del inventario (ADR-0003).
 *
 * Es lo que se compra, almacena en bodega, se mueve y se consume. ÚNICO y
 * GLOBAL: el cemento es uno solo, no se duplica por zona. El inventario
 * (`existencias`, `movimientos_inventario`), las compras (`compra_lineas`)
 * y las requisiciones (`requisicion_lineas`) referencian un material.
 *
 * NO confundir con `Item`: el Item es la base de PRECIO DE VENTA por zona
 * (SRC, TGU, SPS) y alimenta las fichas/APU. Varios items del mismo cemento
 * (uno por zona) apuntan al MISMO material vía `items.material_id`.
 *
 * El COSTO de adquisición no vive aquí: es por bodega y se pondera en la
 * `existencia` correspondiente a partir de las compras. El mismo material
 * puede costar distinto en cada bodega.
 *
 * AUTO-CÓDIGO: {PREFIJO_CATEGORIA}-{NUMERO_5_DIGITOS}, GLOBAL (sin zona).
 * Ej: MAT-00001, HE-00042. Bajo concurrencia, la generación va en
 * transacción con lockForUpdate sobre la categoría — dos usuarios creando
 * simultáneamente NO chocan con duplicate key.
 *
 * UPPERCASE: codigo, nombre y descripcion se normalizan a mayúsculas.
 *
 * @property int $id
 * @property int $unidad_medida_id
 * @property CategoriaItem $categoria
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read UnidadMedida $unidadMedida
 */
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;

    protected $table = 'materiales';

    /** @var list<string> */
    protected $fillable = [
        'unidad_medida_id',
        'categoria',
        'codigo',
        'nombre',
        'descripcion',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categoria' => CategoriaItem::class,
            'activo'    => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'unidad_medida_id',
                'categoria',
                'codigo',
                'nombre',
                'descripcion',
                'activo',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Material {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código global ───────────────

    protected static function booted(): void
    {
        static::creating(static function (Material $material): void {
            if (empty($material->codigo)) {
                $material->codigo = self::generarCodigoSiguiente($material->categoria);
            }
        });
    }

    /**
     * Genera el siguiente código GLOBAL para una categoría física.
     *
     * Patrón: {PREFIJO_CATEGORIA}-{NUMERO_5_DIGITOS}. Ej: MAT-00001, HE-00042.
     * A diferencia de Item, la secuencia es global (no por zona) porque el
     * material físico es único en todo el sistema.
     *
     * Concurrencia: la búsqueda del último número va con `lockForUpdate`
     * dentro de una transacción; creaciones simultáneas en la misma
     * categoría se serializan.
     */
    public static function generarCodigoSiguiente(CategoriaItem $categoria): string
    {
        $patron = self::prefijoCategoria($categoria).'-';

        return DB::transaction(static function () use ($patron): string {
            $ultimo = self::query()
                ->where('codigo', 'like', $patron.'%')
                ->lockForUpdate()
                ->orderByDesc('codigo')
                ->value('codigo');

            $siguienteNum = 1;

            if ($ultimo !== null) {
                $siguienteNum = (int) substr((string) $ultimo, strlen($patron)) + 1;
            }

            return $patron.str_pad((string) $siguienteNum, 5, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Prefijo corto de la categoría física. Solo materiales y herramienta
     * son inventariables; el CHECK de la tabla lo garantiza a nivel DB.
     */
    private static function prefijoCategoria(CategoriaItem $categoria): string
    {
        return match ($categoria) {
            CategoriaItem::Materiales        => 'MAT',
            CategoriaItem::HerramientaEquipo => 'HE',
            CategoriaItem::ManoObra,
            CategoriaItem::Indirectos => throw new InvalidArgumentException(
                "La categoría {$categoria->value} no es inventariable: no puede ser un material."
            ),
        };
    }

    // ─── Mutators uppercase ────────────────────────────────────────

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function codigo(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function nombre(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function descripcion(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<UnidadMedida, $this>
     */
    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class);
    }

    /**
     * Items (precios por zona) que corresponden a este material físico.
     *
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * Existencias (saldos de stock por ubicación) de este material.
     *
     * @return HasMany<Existencia, $this>
     */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeDeCategoria(Builder $query, CategoriaItem $categoria): Builder
    {
        return $query->where('categoria', $categoria->value);
    }
}
