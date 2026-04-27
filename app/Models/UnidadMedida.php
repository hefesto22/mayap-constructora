<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\UnidadMedidaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Catálogo global de unidades de medida.
 *
 * No pertenece a zona — se comparte entre todas. Una unidad solo se
 * deprecia (activo=false), nunca se elimina si tiene items asociados
 * (FK con restrictOnDelete).
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $simbolo
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Item> $items
 */
class UnidadMedida extends Model
{
    /** @use HasFactory<UnidadMedidaFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;

    protected $table = 'unidades_medida';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'simbolo',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'nombre', 'simbolo', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Unidad de medida {$eventName}");
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * Etiqueta legible para selects: "M2 — Metro cuadrado".
     */
    public function getEtiquetaAttribute(): string
    {
        return "{$this->codigo} — {$this->nombre}";
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', true);
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

    protected function simbolo(): Attribute
    {
        // El símbolo NO se uppercase — m² ≠ M², kg ≠ KG visualmente.
        // Solo trim para limpiar espacios accidentales.
        return Attribute::make(
            set: static fn (?string $value): ?string => $value !== null && trim($value) !== ''
                ? trim($value)
                : null,
        );
    }
}
