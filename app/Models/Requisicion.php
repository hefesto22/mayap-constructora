<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoCompra;
use App\Enums\EstadoRequisicion;
use App\Enums\OrigenDespacho;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\RequisicionFactory;
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
 * Requisición de material — cabecera del flujo central del sistema.
 *
 * Una obra pide material; el flujo avanza por estados (EstadoRequisicion)
 * con responsable registrado en cada transición. El avance de estado y la
 * integración con el inventario (despacho con WAC) viven en el Service
 * TransicionarRequisicionService — este modelo solo persiste y consulta.
 *
 * AUTO-CÓDIGO: REQ-{AÑO}-{NUMERO_5} con contador que se reinicia por año,
 * generado en `creating` con lockForUpdate (patrón de Proyecto).
 *
 * @property int $id
 * @property string $codigo
 * @property int $proyecto_id
 * @property EstadoRequisicion $estado
 * @property OrigenDespacho|null $origen_despacho
 * @property int|null $solicitante_id
 * @property Carbon $fecha_solicitud
 * @property Carbon $fecha_necesaria
 * @property Carbon|null $fecha_estimada_llegada
 * @property Carbon|null $aviso_llegada_obra_at
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Proyecto $proyecto
 * @property-read User|null $solicitante
 */
class Requisicion extends Model
{
    /** @use HasFactory<RequisicionFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'requisiciones';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'proyecto_id',
        'estado',
        'origen_despacho',
        'solicitante_id',
        'fecha_solicitud',
        'fecha_necesaria',
        'fecha_estimada_llegada',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'estado'                 => EstadoRequisicion::class,
            'origen_despacho'        => OrigenDespacho::class,
            'fecha_solicitud'        => 'date',
            'fecha_necesaria'        => 'date',
            'fecha_estimada_llegada' => 'date',
            'aviso_llegada_obra_at'  => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'proyecto_id', 'estado', 'origen_despacho', 'solicitante_id', 'fecha_necesaria', 'fecha_estimada_llegada', 'notas'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Requisición {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    #[Override]
    protected static function booted(): void
    {
        static::creating(static function (Requisicion $requisicion): void {
            if (empty($requisicion->codigo)) {
                $anio = ($requisicion->fecha_solicitud instanceof Carbon)
                    ? $requisicion->fecha_solicitud->year
                    : (int) now()->year;

                $requisicion->codigo = self::generarCodigoSiguiente($anio);
            }
        });
    }

    /**
     * Genera el siguiente código secuencial REQ-{AÑO}-{NUMERO_5}.
     *
     * El contador se reinicia cada año. Concurrencia: lockForUpdate dentro
     * de transacción serializa creaciones simultáneas.
     */
    public static function generarCodigoSiguiente(int $anio): string
    {
        $patron = "REQ-{$anio}-";

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

    // ─── Reglas de dominio ─────────────────────────────────────────

    /**
     * ¿La fecha necesaria ya venció? Vencida = estrictamente ANTES de hoy:
     * si la fecha necesaria es HOY, todavía se puede atender a tiempo.
     *
     * Una Solicitada vencida NO se puede autorizar: primero se reprograma
     * (TransicionarRequisicionService::reprogramar, con motivo en bitácora)
     * o se rechaza.
     */
    public function fechaNecesariaVencida(): bool
    {
        return $this->fecha_necesaria->lt(today());
    }

    /**
     * ¿El material lo entregó el proveedor directo en la obra? Es lo que
     * decide si el flujo pasa por "En tránsito" o va derecho a la
     * confirmación de recepción.
     */
    public function esDespachoDirecto(): bool
    {
        return $this->origen_despacho?->esCompraDirecta() === true;
    }

    /**
     * Estados a los que ESTA requisición puede avanzar (el mapa del enum
     * ya filtrado por su origen de despacho). Única puerta de consulta
     * para la UI: ningún Resource debe reconstruir la regla.
     *
     * @return array<int, EstadoRequisicion>
     */
    public function transicionesPermitidas(): array
    {
        return $this->estado->transicionesPermitidas($this->origen_despacho);
    }

    public function puedeTransicionarA(EstadoRequisicion $destino): bool
    {
        return $this->estado->puedeTransicionarA($destino, $this->origen_despacho);
    }

    /**
     * ¿Está esperando que llegue una compra? Es la ventana real de
     * incertidumbre para la obra: pidió material, no había stock, y ahora
     * depende de que el proveedor cumpla.
     */
    public function esperandoLlegada(): bool
    {
        return $this->estado === EstadoRequisicion::RequisicionCompra
            && $this->fecha_estimada_llegada !== null;
    }

    /**
     * ¿La llegada prometida cae DESPUÉS de la fecha en que la obra dijo
     * necesitar el material? (el ámbar del semáforo).
     */
    public function llegaTarde(): bool
    {
        return $this->fecha_estimada_llegada !== null
            && $this->fecha_estimada_llegada->gt($this->fecha_necesaria);
    }

    /**
     * ¿Pasó la fecha prometida y el material sigue sin llegar? (el rojo).
     */
    public function llegadaVencida(): bool
    {
        return $this->estado === EstadoRequisicion::RequisicionCompra
            && $this->fecha_estimada_llegada !== null
            && $this->fecha_estimada_llegada->lt(today());
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * Compras hechas para cubrir esta requisición (compras.requisicion_id).
     *
     * @return HasMany<Compra, $this>
     */
    public function compras(): HasMany
    {
        return $this->hasMany(Compra::class);
    }

    /**
     * La compra que todavía viene en camino para esta requisición (la más
     * reciente en "por recibir"). Es de donde sale la fecha de llegada que
     * ve la obra.
     */
    public function compraEnCamino(): ?Compra
    {
        return $this->compras()
            ->where('estado', EstadoCompra::PorRecibir->value)
            ->latest('id')
            ->first();
    }

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    /**
     * @return HasMany<RequisicionLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(RequisicionLinea::class);
    }

    /**
     * @return HasMany<RequisicionTransicion, $this>
     */
    public function transiciones(): HasMany
    {
        return $this->hasMany(RequisicionTransicion::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeEnEstado(Builder $query, EstadoRequisicion $estado): Builder
    {
        return $query->where('estado', $estado->value);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeDeProyecto(Builder $query, int $proyectoId): Builder
    {
        return $query->where('proyecto_id', $proyectoId);
    }

    /**
     * Requisiciones que aún están en curso (no terminales).
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->whereNotIn('estado', [
            EstadoRequisicion::Cerrada->value,
            EstadoRequisicion::Discrepancia->value,
            EstadoRequisicion::Rechazada->value,
        ]);
    }
}
