<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoriaItem;
use App\Models\Concerns\HasUppercaseAttributes;
use App\Observers\ItemObserver;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Item de la base de precios — ÚNICO punto de verdad para precios.
 *
 * Todo cálculo de fichas APU (Sprint 2) y presupuestos (Sprint 3)
 * referencia un item de aquí. Cuando un presupuesto se EMITE, se
 * congela un snapshot JSONB con los precios vigentes en ese momento;
 * desde ahí en adelante los cambios en este precio NO afectan al
 * presupuesto emitido — preserva integridad histórica del documento.
 *
 * AUTO-CÓDIGO: el campo `codigo` se genera automáticamente al crear
 * cuando viene vacío. Patrón: {ZONA}-{CATEGORIA}-{NUMERO_5_DIGITOS}.
 * Ej: SRC-MAT-00001 (primer material en Santa Rosa de Copán).
 * Bajo concurrencia, la generación va en transacción con lockForUpdate
 * sobre la zona+categoría — dos usuarios creando simultáneamente
 * NO chocan con duplicate key.
 *
 * MUTATORS UPPERCASE: nombre, descripción, observaciones_precio y código
 * se uppercase automáticamente al asignar. Triple defensa con el form
 * (CSS visual + dehydrate) garantiza consistencia incluso ante imports.
 *
 * `precio_actualizado_at` lo gestiona ItemObserver: solo cambia cuando
 * cambia `precio_unitario`. Permite filtrar "precios viejos" sin que
 * un edit de descripción ensucie el indicador.
 *
 * @property int $id
 * @property int $zona_id
 * @property int $unidad_medida_id
 * @property CategoriaItem $categoria
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property string $precio_unitario
 * @property string|null $observaciones_precio
 * @property Carbon|null $precio_actualizado_at
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Zona           $zona
 * @property-read UnidadMedida   $unidadMedida
 */
#[ObservedBy([ItemObserver::class])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'zona_id',
        'unidad_medida_id',
        'categoria',
        'codigo',
        'nombre',
        'descripcion',
        'precio_unitario',
        'observaciones_precio',
        'precio_actualizado_at',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categoria'             => CategoriaItem::class,
            'precio_unitario'       => 'decimal:2',
            'precio_actualizado_at' => 'datetime',
            'activo'                => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'zona_id',
                'unidad_medida_id',
                'categoria',
                'codigo',
                'nombre',
                'descripcion',
                'precio_unitario',
                'observaciones_precio',
                'activo',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Item {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (Item $item): void {
            if (empty($item->codigo)) {
                $item->codigo = self::generarCodigoSiguiente($item->zona_id, $item->categoria);
            }
        });
    }

    /**
     * Genera el siguiente código secuencial para una zona + categoría.
     *
     * Patrón: {CODIGO_ZONA}-{PREFIJO_CATEGORIA}-{NUMERO_5_DIGITOS}
     * Ejemplos: SRC-MAT-00001, TGU-HE-00042
     *
     * Concurrencia: la búsqueda del último número va con `lockForUpdate`
     * dentro de una transacción. Dos requests creando items simultáneos
     * en la misma zona+categoría se serializan — el segundo espera al
     * primer commit antes de calcular su número.
     */
    public static function generarCodigoSiguiente(int $zonaId, CategoriaItem $categoria): string
    {
        $zona = Zona::findOrFail($zonaId);
        $prefijoCat = self::prefijoCategoria($categoria);
        $patron = "{$zona->codigo}-{$prefijoCat}-";

        return DB::transaction(static function () use ($zonaId, $patron): string {
            $ultimo = self::query()
                ->where('zona_id', $zonaId)
                ->where('codigo', 'like', $patron.'%')
                ->lockForUpdate()
                ->orderByDesc('codigo')
                ->value('codigo');

            $siguienteNum = 1;

            if ($ultimo !== null) {
                $sufijo = (int) substr((string) $ultimo, strlen($patron));
                $siguienteNum = $sufijo + 1;
            }

            return $patron.str_pad((string) $siguienteNum, 5, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Prefijo corto del enum CategoriaItem para usar en códigos.
     *
     * Mantenido como método interno para que la convención esté en un
     * solo lugar. Si en el futuro se renombra una categoría, el prefijo
     * histórico debe permanecer inmutable (los items existentes ya
     * llevan ese prefijo en su código).
     */
    private static function prefijoCategoria(CategoriaItem $categoria): string
    {
        return match ($categoria) {
            CategoriaItem::Materiales        => 'MAT',
            CategoriaItem::ManoObra          => 'MO',
            CategoriaItem::HerramientaEquipo => 'HE',
            CategoriaItem::Indirectos        => 'IND',
        };
    }

    // ─── Mutators uppercase ────────────────────────────────────────

    protected function codigo(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    protected function nombre(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    protected function descripcion(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    protected function observacionesPrecio(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<Zona, $this>
     */
    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class);
    }

    /**
     * @return BelongsTo<UnidadMedida, $this>
     */
    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class);
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
    public function scopeDeZona(Builder $query, int $zonaId): Builder
    {
        return $query->where('zona_id', $zonaId);
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

    /**
     * Items con precio sin actualizar hace más de N días.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePreciosDesactualizados(Builder $query, int $dias = 90): Builder
    {
        return $query->where(static function (Builder $q) use ($dias): void {
            $q->whereNull('precio_actualizado_at')
                ->orWhere('precio_actualizado_at', '<', now()->subDays($dias));
        });
    }
}
