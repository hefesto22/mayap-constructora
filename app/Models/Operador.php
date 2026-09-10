<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\OperadorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Operador — la PERSONA que maneja una máquina (decisión Mauricio
 * 2026-09-05).
 *
 * Que esté o no en planilla es un dato suyo, no dos mundos distintos:
 * `empleado_id` liga al de planilla y queda en null para el externo. Por
 * eso el catálogo es propio y no una lista de empleados — de otro modo
 * la mitad de los operadores de la constructora no existirían.
 *
 * Cada máquina guarda su operador habitual y el parte del día lo trae
 * puesto; se cambia solo el día que maneja otro.
 *
 * AUTO-CÓDIGO: OPE-{NUMERO_5} global, generado en `creating` con
 * lockForUpdate (mismo patrón que Máquina/Empleado).
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property int|null $empleado_id
 * @property string|null $telefono
 * @property string|null $licencia
 * @property string|null $notas
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Empleado|null $empleado
 */
class Operador extends Model
{
    /** @use HasFactory<OperadorFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'operadores';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'empleado_id',
        'telefono',
        'licencia',
        'notas',
        'activo',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'activo' => true,
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function nombre(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $v): ?string => self::aMayusculas($v),
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function notas(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $v): ?string => self::aMayusculas($v),
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'nombre', 'empleado_id', 'telefono', 'licencia', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    #[Override]
    protected static function booted(): void
    {
        static::creating(static function (Operador $operador): void {
            if (empty($operador->codigo)) {
                $operador->codigo = self::generarCodigoSiguiente();
            }
        });
    }

    public static function generarCodigoSiguiente(): string
    {
        $patron = 'OPE-';

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

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * El empleado de planilla, cuando lo es. Null = operador EXTERNO.
     *
     * @return BelongsTo<Empleado, $this>
     */
    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    /**
     * Las máquinas que tiene asignadas como operador habitual.
     *
     * @return HasMany<Maquina, $this>
     */
    public function maquinas(): HasMany
    {
        return $this->hasMany(Maquina::class, 'operador_habitual_id');
    }

    /**
     * @return HasMany<ParteTrabajo, $this>
     */
    public function partes(): HasMany
    {
        return $this->hasMany(ParteTrabajo::class);
    }

    /**
     * @return HasMany<ConsumoCombustible, $this>
     */
    public function consumos(): HasMany
    {
        return $this->hasMany(ConsumoCombustible::class);
    }

    // ─── Estado ────────────────────────────────────────────────────

    /**
     * Externo = no está en planilla. No es un demérito: es el dato que
     * decide si su pago sale de planilla o de una factura de servicio.
     */
    public function esExterno(): bool
    {
        return $this->empleado_id === null;
    }

    /**
     * Nombre con su procedencia, para listas donde conviven los dos.
     */
    public function etiqueta(): string
    {
        return $this->nombre.($this->esExterno() ? ' (externo)' : '');
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
     * Opciones para los selects del módulo, con el externo marcado.
     *
     * @return array<int, string>
     */
    public static function opciones(): array
    {
        return self::query()
            ->activos()
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'empleado_id'])
            ->mapWithKeys(fn (self $o): array => [$o->id => $o->etiqueta()])
            ->all();
    }
}
