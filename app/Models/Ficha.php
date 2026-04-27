<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\FichaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Ficha de Análisis de Precio Unitario (APU).
 *
 * Receta que define el costo por unidad de salida de un trabajo de obra.
 * Pertenece a una zona (la base de precios que usa) y tiene una unidad
 * de salida propia (M², ML, M³, GLB).
 *
 * Las líneas que la componen viven en `ficha_lineas` — cada una puede
 * ser un item del catálogo (con rendimiento + desperdicio) o una línea
 * derivada tipo porcentaje (ej: HERRAMIENTA MENOR 5% sobre MO).
 *
 * AUTO-CÓDIGO: el campo `codigo` se genera automáticamente al crear
 * cuando viene vacío. Patrón: {ZONA}-APU-{NUMERO_5_DIGITOS}.
 * Ej: SRC-APU-00001 (primera ficha en Santa Rosa de Copán).
 * Bajo concurrencia, va en transacción con lockForUpdate.
 *
 * MUTATORS UPPERCASE: nombre, descripción y código se uppercase
 * automáticamente al asignar. Triple defensa con form (CSS visual +
 * dehydrate) garantiza consistencia incluso ante imports.
 *
 * CACHE DE PRECIO: `costo_directo_cache` y `precio_venta_cache` se
 * actualizan al guardar la ficha (CalcularPrecioFichaService::recalcular).
 * Si los precios de items cambian externamente, el cache puede quedar
 * stale; existe acción manual "Recalcular fichas" + indicador visual
 * cuando `precio_calculado_at < max(items.precio_actualizado_at)`.
 *
 * @property int $id
 * @property int $zona_id
 * @property int $unidad_medida_id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $descripcion
 * @property array<string, scalar>|null $parametros_tecnicos
 * @property string $utilidad_porcentaje
 * @property string $subtotal_cache
 * @property string $precio_venta_cache
 * @property Carbon|null $precio_calculado_at
 * @property bool $activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Zona $zona
 * @property-read UnidadMedida $unidadMedida
 * @property-read Collection<int, FichaLinea> $lineas
 */
class Ficha extends Model
{
    /** @use HasFactory<FichaFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;

    /** @var list<string> */
    protected $fillable = [
        'zona_id',
        'unidad_medida_id',
        'codigo',
        'nombre',
        'descripcion',
        'parametros_tecnicos',
        'utilidad_porcentaje',
        'subtotal_cache',
        'precio_venta_cache',
        'precio_calculado_at',
        'activa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parametros_tecnicos' => 'array',
            'utilidad_porcentaje' => 'decimal:2',
            'subtotal_cache'      => 'decimal:2',
            'precio_venta_cache'  => 'decimal:2',
            'precio_calculado_at' => 'datetime',
            'activa'              => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'zona_id',
                'unidad_medida_id',
                'codigo',
                'nombre',
                'descripcion',
                'parametros_tecnicos',
                'utilidad_porcentaje',
                'activa',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Ficha {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (Ficha $ficha): void {
            if (empty($ficha->codigo)) {
                $ficha->codigo = self::generarCodigoSiguiente($ficha->zona_id);
            }
        });
    }

    /**
     * Genera el siguiente código secuencial para una zona.
     *
     * Patrón: {CODIGO_ZONA}-APU-{NUMERO_5_DIGITOS}
     * Ejemplo: SRC-APU-00001, TGU-APU-00042.
     *
     * Concurrencia: la búsqueda del último número va con `lockForUpdate`
     * dentro de una transacción. Dos requests creando fichas simultáneas
     * en la misma zona se serializan — el segundo espera al primer
     * commit antes de calcular su número.
     */
    public static function generarCodigoSiguiente(int $zonaId): string
    {
        $zona = Zona::findOrFail($zonaId);
        $patron = "{$zona->codigo}-APU-";

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

    /**
     * @return HasMany<FichaLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(FichaLinea::class)->orderBy('orden');
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activa', true);
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
     * Fichas cuyo cache de precio puede estar stale: el precio_calculado_at
     * es nulo, o es anterior a la última actualización de algún item que
     * la ficha referencia.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeCacheDesactualizado(Builder $query): Builder
    {
        return $query->where(static function (Builder $q): void {
            $q->whereNull('precio_calculado_at')
                ->orWhereExists(static function ($subQuery): void {
                    $subQuery->select(DB::raw(1))
                        ->from('ficha_lineas')
                        ->join('items', 'items.id', '=', 'ficha_lineas.item_id')
                        ->whereColumn('ficha_lineas.ficha_id', 'fichas.id')
                        ->whereColumn('items.precio_actualizado_at', '>', 'fichas.precio_calculado_at');
                });
        });
    }
}
