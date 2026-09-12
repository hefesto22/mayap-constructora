<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\BodegaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Bodega física — ubicación de stock real de la constructora.
 *
 * Junto con los `proyectos`, son los dos tipos de ubicación donde vive el
 * inventario (ver `Existencia`). Una bodega es típicamente el almacén
 * central; un proyecto actúa como mini-bodega de obra.
 *
 * CONTENEDORES MÓVILES (Mauricio 2026-09-10): una bodega puede VIAJAR —
 * la pipa de agua, la cisterna de diésel, la camioneta de herramienta.
 * Es el tercer tipo de lugar que faltaba: uno que se mueve. Y como sigue
 * siendo una bodega, su existencia ya dice cuánto carga ahora mismo, salir
 * a la obra es un despacho normal con su costo promedio, y los reportes
 * de siempre contestan "cuánta agua salió" sin nada nuevo.
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
 * @property bool $movil
 * @property string|null $capacidad
 * @property int|null $maquina_id
 * @property int|null $material_id
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
        'movil',
        'capacidad',
        'maquina_id',
        'material_id',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'activo'    => 'boolean',
            'movil'     => 'boolean',
            'capacidad' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'nombre', 'direccion', 'responsable', 'activo', 'movil', 'capacidad', 'maquina_id', 'material_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Bodega {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    #[Override]
    protected static function booted(): void
    {
        static::creating(static function (Bodega $bodega): void {
            if (empty($bodega->codigo)) {
                $bodega->codigo = self::generarCodigoSiguiente();
            }
        });
    }

    // ─── Contenedor móvil ──────────────────────────────────────────

    /**
     * El vehículo que la carga, si es un contenedor que viaja.
     *
     * @return BelongsTo<Maquina, $this>
     */
    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class);
    }

    /**
     * Qué carga este contenedor. Saberlo es lo que permite que el regreso
     * no pregunte "¿de qué?" — solo "¿con cuánto viene?".
     *
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * ¿Es un contenedor que viaja? (pipa, cisterna, camioneta de
     * herramienta) — en oposición a una bodega fija.
     */
    public function esMovil(): bool
    {
        // Cast explícito: si el atributo no viene cargado (un select
        // parcial, un modelo recién construido) el cast 'boolean' entrega
        // null, y devolver null desde un `: bool` revienta con TypeError.
        return (bool) $this->movil;
    }

    /**
     * ¿Carga SIEMPRE lo mismo? (pipa de agua, cisterna de diésel).
     *
     * Se sabe por el material declarado, no por una bandera aparte: si
     * dice qué carga, carga eso. Una bandera podría contradecirlo.
     */
    public function esDeGranel(): bool
    {
        return $this->esMovil() && $this->material_id !== null;
    }

    /**
     * ¿Carga lo que se le suba? (volqueta, camioneta de reparto). Lleva a
     * la obra lo que diga la requisición, así que su regreso no se mide
     * con un nivel sino con "¿entregaste todo?".
     */
    public function esDeReparto(): bool
    {
        return $this->esMovil() && $this->material_id === null;
    }

    /**
     * Cuánto carga AHORA MISMO de su material. No es un dato aparte: es su
     * existencia de siempre, leída donde importa.
     *
     * @return numeric-string
     */
    public function contenidoActual(): string
    {
        if ($this->material_id === null) {
            return '0';
        }

        $cantidad = Existencia::query()
            ->where('bodega_id', $this->id)
            ->where('material_id', $this->material_id)
            ->value('cantidad');

        return is_numeric($cantidad) ? (string) $cantidad : '0';
    }

    /**
     * Qué tan lleno viene, de 0 a 100. Null cuando no hay capacidad
     * declarada: sin ella "a la mitad" no significa nada.
     */
    public function porcentajeLleno(): ?int
    {
        $capacidad = (string) $this->capacidad;

        if (! is_numeric($capacidad) || bccomp($capacidad, '0', 4) <= 0) {
            return null;
        }

        $porcentaje = (int) round((float) bcdiv($this->contenidoActual(), $capacidad, 6) * 100);

        return max(0, min(100, $porcentaje));
    }

    /**
     * ¿Se quedó sin nada? Es la señal de "hay que mandarla a llenar".
     */
    public function estaVacio(): bool
    {
        return bccomp($this->contenidoActual(), '0', 4) <= 0;
    }

    /**
     * ¿Lleva algo encima ahora mismo? Para un vehículo de reparto es la
     * pregunta que importa: si vuelve a base con existencia, o la obra no
     * ha confirmado todavía o el material se regresó.
     */
    public function llevaCarga(): bool
    {
        return $this->existencias()->where('cantidad', '>', 0)->exists();
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeMoviles(Builder $query): Builder
    {
        return $query->where('movil', true);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeFijas(Builder $query): Builder
    {
        return $query->where('movil', false);
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
     * `VerTodasLasBodegas:Bodega` ve todas. Útil para selectores (entrada,
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
