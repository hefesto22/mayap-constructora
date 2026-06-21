<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\BodegaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Bodega física — ubicación de stock real de la constructora.
 *
 * Junto con los `proyectos`, son los dos tipos de ubicación donde vive el
 * inventario (ver `Existencia`). Una bodega es típicamente el almacén
 * central; un proyecto actúa como mini-bodega de obra.
 *
 * AUTO-CÓDIGO: BOD-{NUMERO_5_DIGITOS} generado en `creating` con
 * lockForUpdate (mismo patrón que Item/Proyecto). Bajo concurrencia, dos
 * bodegas creadas a la vez se serializan y no chocan con duplicate key.
 *
 * UPPERCASE: codigo, nombre y direccion se normalizan a mayúsculas. El
 * responsable NO (es nombre de persona, ver convención del proyecto).
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $direccion
 * @property string|null $responsable
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Bodega extends Model
{
    /** @use HasFactory<BodegaFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'direccion',
        'responsable',
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
            ->logOnly(['codigo', 'nombre', 'direccion', 'responsable', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Bodega {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (Bodega $bodega): void {
            if (empty($bodega->codigo)) {
                $bodega->codigo = self::generarCodigoSiguiente();
            }
        });
    }

    /**
     * Genera el siguiente código secuencial: BOD-00001, BOD-00002, ...
     *
     * Concurrencia: la búsqueda del último número va con lockForUpdate
     * dentro de una transacción; creaciones simultáneas se serializan.
     */
    public static function generarCodigoSiguiente(): string
    {
        $patron = 'BOD-';

        return DB::transaction(static function () use ($patron): string {
            $ultimo = self::withTrashed()
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

    protected function direccion(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return HasMany<Existencia, $this>
     */
    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    /**
     * Usuarios asignados a esta bodega (Fase 2: visibilidad por bodega).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Limita las bodegas a las asignadas al usuario (Fase 2). Quien tiene
     * `ver_todas_las_bodegas` ve todas. Útil para selectores (entrada,
     * compra, despacho) donde el usuario solo debe elegir SU bodega.
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

        return $query->whereIn('id', $usuario->bodegasAsignadasIds());
    }
}
