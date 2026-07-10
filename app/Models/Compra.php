<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CondicionPago;
use App\Enums\EstadoCompra;
use App\Models\Concerns\HasUppercaseAttributes;
use App\Services\Inventario\Ubicacion;
use Database\Factories\CompraFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
 * @property string|null $motivo_anulacion
 * @property Carbon|null $anulada_at
 * @property int|null $anulada_por
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
        'proyecto_id',
        'requisicion_id',
        'estado',
        'condicion_pago',
        'fecha',
        'fecha_recepcion',
        'numero_factura',
        'aplica_isv',
        'isv_porcentaje',
        'costo_envio',
        'descuento',
        'subtotal_cache',
        'isv_cache',
        'total_cache',
        'notas',
        'motivo_anulacion',
        'anulada_at',
        'anulada_por',
        'completada_at',
        'completada_por',
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
            'anulada_at'      => 'datetime',
            'completada_at'   => 'datetime',
            'aplica_isv'      => 'boolean',
            'isv_porcentaje'  => 'decimal:2',
            'costo_envio'     => 'decimal:2',
            'descuento'       => 'decimal:2',
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
     * Obra destino cuando la compra se entrega directo (XOR con bodega).
     *
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * Requisición que originó la compra (trazabilidad / despacho directo).
     *
     * @return BelongsTo<Requisicion, $this>
     */
    public function requisicion(): BelongsTo
    {
        return $this->belongsTo(Requisicion::class);
    }

    /**
     * ¿La compra se entrega directo a una obra (sin pasar por bodega)?
     */
    public function esDirectaAObra(): bool
    {
        return $this->proyecto_id !== null;
    }

    /**
     * Destino efectivo de una línea — ÚNICA fuente de la regla: el propio
     * si lo define, si no el de la cabecera (bodega XOR obra, garantizado
     * por CHECKs). La consumen confirmación, notificaciones y verificación.
     */
    public function destinoDeLinea(CompraLinea $linea): Ubicacion
    {
        if ($linea->proyecto_id !== null) {
            return Ubicacion::obra($linea->proyecto_id);
        }

        if ($linea->bodega_id !== null) {
            return Ubicacion::bodega($linea->bodega_id);
        }

        return $this->esDirectaAObra()
            ? Ubicacion::obra((int) $this->proyecto_id)
            : Ubicacion::bodega($this->bodega_id);
    }

    // ─── Cierre (conciliación final) ────────────────────────────────

    /**
     * ¿Todo cuadró? Cada línea verificada y SIN diferencia (facturado =
     * recibido). Solo entonces la compra puede COMPLETARSE.
     */
    public function cuadrada(): bool
    {
        $this->loadMissing('lineas');

        return $this->lineas->isNotEmpty()
            && $this->lineas->every(
                fn (CompraLinea $l): bool => $l->verificada() && ! $l->tieneDiferencia(),
            );
    }

    /**
     * Momento en que quedó cuadrada = el ÚLTIMO conteo (verificación o
     * corrección — corregir reinicia el reloj). De aquí corre la ventana
     * de corrección.
     */
    public function cuadradaEn(): ?Carbon
    {
        if (! $this->cuadrada()) {
            return null;
        }

        $ultima = $this->lineas->max('verificada_at');

        return $ultima instanceof Carbon ? $ultima : null;
    }

    /**
     * ¿Sigue abierta la ventana para corregir conteos?
     *
     *  - Con diferencias (no cuadra): la ventana NUNCA cierra — el reclamo
     *    se resuelve recontando o anulando.
     *  - Cuadrada: cierra a las N horas del último conteo (config
     *    compras.ventana_correccion_horas) o al COMPLETAR, lo que ocurra
     *    primero.
     */
    public function enVentanaDeCorreccion(): bool
    {
        if ($this->estado === EstadoCompra::Completada) {
            return false;
        }

        $cuadradaEn = $this->cuadradaEn();

        if ($cuadradaEn === null) {
            return true;
        }

        return now()->lessThan(
            $cuadradaEn->copy()->addHours((int) config('compras.ventana_correccion_horas', 24)),
        );
    }

    /**
     * ¿Ya venció la ventana y quedó lista para el cierre definitivo?
     */
    public function listaParaCompletar(): bool
    {
        return $this->estado === EstadoCompra::Confirmada
            && $this->cuadrada()
            && ! $this->enVentanaDeCorreccion();
    }

    /**
     * @return HasMany<CompraLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(CompraLinea::class);
    }

    /**
     * Cuenta por pagar generada al confirmar (solo compras a crédito).
     *
     * @return HasOne<CuentaPorPagar, $this>
     */
    public function cuentaPorPagar(): HasOne
    {
        return $this->hasOne(CuentaPorPagar::class);
    }

    /**
     * Movimientos de inventario que esta compra originó (entradas, consumo
     * inmediato, anulación) — la trazabilidad documento ↔ libro mayor.
     *
     * @return MorphMany<MovimientoInventario, $this>
     */
    public function movimientos(): MorphMany
    {
        return $this->morphMany(MovimientoInventario::class, 'referencia');
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

    /**
     * Limita las compras a la(s) bodega(s) que el usuario puede ver (Fase 2).
     * Quien tiene `VerTodasLasBodegas:Bodega` (super_admin, gerencia) ve todas.
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

        return $query->whereIn('bodega_id', $usuario->bodegasAsignadasIds());
    }
}
