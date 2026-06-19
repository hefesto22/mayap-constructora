<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\CompraFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Compra a proveedor — documento que, al confirmarse, registra entradas de
 * inventario (vía RegistrarMovimientoService) que alimentan el promedio
 * ponderado. El avance de estado vive en el Service (ConfirmarCompra).
 *
 * AUTO-CÓDIGO: COM-{AÑO}-{NUMERO_5}, contador por año (patrón de Proyecto).
 *
 * @property int $id
 * @property string $codigo
 * @property int $proveedor_id
 * @property int $bodega_id
 * @property EstadoCompra $estado
 * @property CondicionPago $condicion_pago
 * @property Carbon $fecha
 * @property Carbon|null $fecha_recepcion
 * @property string|null $numero_factura
 * @property bool $aplica_isv
 * @property string $isv_porcentaje
 * @property string $subtotal_cache
 * @property string $isv_cache
 * @property string $total_cache
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Proveedor $proveedor
 * @property-read Bodega $bodega
 */
class Compra extends Model
{
    /** @use HasFactory<CompraFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'compras';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'proveedor_id',
        'bodega_id',
        'estado',
        'condicion_pago',
        'fecha',
        'fecha_recepcion',
        'numero_factura',
        'aplica_isv',
        'isv_porcentaje',
        'subtotal_cache',
        'isv_cache',
        'total_cache',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'          => EstadoCompra::class,
            'condicion_pago'  => CondicionPago::class,
            'fecha'           => 'date',
            'fecha_recepcion' => 'date',
            'aplica_isv'      => 'boolean',
            'isv_porcentaje'  => 'decimal:2',
            'subtotal_cache'  => 'decimal:2',
            'isv_cache'       => 'decimal:2',
            'total_cache'     => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'proveedor_id', 'bodega_id', 'estado', 'condicion_pago', 'fecha', 'numero_factura', 'total_cache'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Compra {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (Compra $compra): void {
            if (empty($compra->codigo)) {
                $anio = ($compra->fecha instanceof Carbon)
                    ? $compra->fecha->year
                    : (int) now()->year;

                $compra->codigo = self::generarCodigoSiguiente($anio);
            }
        });
    }

    /**
     * Genera el siguiente código secuencial COM-{AÑO}-{NUMERO_5}.
     */
    public static function generarCodigoSiguiente(int $anio): string
    {
        $patron = "COM-{$anio}-";

        return DB::transaction(static function () use ($patron): string {
            $ultimo = self::query()
                ->withTrashed()
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

    protected function notas(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * @return BelongsTo<Bodega, $this>
     */
    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    /**
     * @return HasMany<CompraLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(CompraLinea::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeEnEstado(Builder $query, EstadoCompra $estado): Builder
    {
        return $query->where('estado', $estado->value);
    }
}
