<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrigenGastoReparacion;
use App\Enums\TipoCargoObra;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\GastoMantenimientoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Gasto de una reparación — lo que costó dejar la máquina trabajando
 * otra vez (decisión Mauricio 2026-09-05).
 *
 * Vive en la MÁQUINA (su historial) y anota en qué obra pasó. Que además
 * se le cargue al proyecto es una DECISIÓN de recepción, no automática:
 * una avería no siempre la causa la obra donde ocurrió.
 *
 * Dos puertas de entrada: el encargado que compró en la obra lo anota y
 * queda PENDIENTE de conciliar; recepción lo registra con su factura y
 * nace conciliado.
 *
 * AUTO-CÓDIGO: GRE-{AÑO}-{NUMERO_5}.
 *
 * @property int $id
 * @property string $codigo
 * @property int $mantenimiento_id
 * @property int $maquina_id
 * @property int|null $proyecto_id
 * @property int|null $material_id
 * @property int|null $bodega_id
 * @property numeric-string|null $cantidad
 * @property Carbon $fecha
 * @property string $descripcion
 * @property numeric-string $monto
 * @property OrigenGastoReparacion $origen
 * @property int|null $registrado_por
 * @property int|null $compra_id
 * @property bool $cargar_a_proyecto
 * @property numeric-string|null $monto_obra
 * @property TipoCargoObra|null $tipo_cargo_obra
 * @property Carbon|null $conciliado_at
 * @property int|null $conciliado_por
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read MantenimientoMaquina $mantenimiento
 * @property-read Maquina $maquina
 * @property-read Proyecto|null $proyecto
 * @property-read Compra|null $compra
 * @property-read Material|null $material
 * @property-read Bodega|null $bodega
 */
class GastoMantenimiento extends Model
{
    /** @use HasFactory<GastoMantenimientoFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'gastos_mantenimiento';

    /** @var array<string, mixed> */
    protected $attributes = [
        'origen'            => 'recepcion',
        'cargar_a_proyecto' => false,
    ];

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'mantenimiento_id',
        'maquina_id',
        'proyecto_id',
        'material_id',
        'bodega_id',
        'cantidad',
        'fecha',
        'descripcion',
        'monto',
        'origen',
        'registrado_por',
        'compra_id',
        'cargar_a_proyecto',
        'monto_obra',
        'tipo_cargo_obra',
        'conciliado_at',
        'conciliado_por',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'fecha'             => 'date',
            'monto'             => 'decimal:2',
            'monto_obra'        => 'decimal:2',
            'cantidad'          => 'decimal:4',
            'tipo_cargo_obra'   => TipoCargoObra::class,
            'origen'            => OrigenGastoReparacion::class,
            'cargar_a_proyecto' => 'boolean',
            'conciliado_at'     => 'datetime',
        ];
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function descripcion(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $v): ?string => self::aMayusculas($v),
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'maquina_id', 'proyecto_id', 'monto', 'cargar_a_proyecto', 'compra_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    #[Override]
    protected static function booted(): void
    {
        static::creating(static function (GastoMantenimiento $gasto): void {
            if (empty($gasto->codigo)) {
                $gasto->codigo = self::generarCodigoSiguiente();
            }
        });
    }

    public static function generarCodigoSiguiente(): string
    {
        $patron = 'GRE-'.now()->year.'-';

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
     * @return BelongsTo<MantenimientoMaquina, $this>
     */
    public function mantenimiento(): BelongsTo
    {
        return $this->belongsTo(MantenimientoMaquina::class, 'mantenimiento_id');
    }

    /**
     * @return BelongsTo<Maquina, $this>
     */
    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class);
    }

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * @return BelongsTo<Compra, $this>
     */
    public function compra(): BelongsTo
    {
        return $this->belongsTo(Compra::class);
    }

    /**
     * El repuesto que salió de bodega, cuando no se compró nada.
     *
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    // ─── Estado ────────────────────────────────────────────────────

    /**
     * Pendiente = lo anotó la obra y recepción todavía no lo respaldó.
     * Es la bandeja de trabajo de recepción.
     */
    public function estaPendiente(): bool
    {
        return $this->conciliado_at === null;
    }

    /**
     * Lo que se le carga a la obra. Si nadie escribió un monto propio,
     * es el costo tal cual — cobrar distinto es la excepción, no la regla.
     *
     * @return numeric-string
     */
    public function montoALaObra(): string
    {
        return number_format(
            (float) ($this->monto_obra ?? $this->monto),
            2,
            '.',
            '',
        );
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->whereNull('conciliado_at');
    }

    /**
     * Los que recepción decidió cargarle a la obra COMO GASTO: son los
     * únicos que entran al costo del proyecto. Lo que se le cobra al
     * cliente no abarata ni encarece la obra.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeCargadosAObra(Builder $query): Builder
    {
        return $query
            ->where('cargar_a_proyecto', true)
            ->whereNotNull('proyecto_id')
            // El COBRO no es costo de la obra: es dinero que entra.
            ->where('tipo_cargo_obra', TipoCargoObra::Gasto->value);
    }
}
