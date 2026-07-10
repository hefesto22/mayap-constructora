<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoProyecto;
use App\Enums\ModoPlazo;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\ProyectoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Proyecto / Cotización — la entidad central de Sprint 3.
 *
 * Representa una cotización formal entregada a un cliente para una
 * obra específica. Compuesta de renglones que combinan ficha APU ×
 * cantidad, agrupados por capítulos para presentación.
 *
 * RESTRICCIÓN DE ZONA: el proyecto vive en UNA zona inmutable. Todas
 * sus fichas DEBEN ser de esa misma zona (los precios dependen de la
 * zona). La validación vive en `AgregarRenglonAProyectoService` y en
 * el Form Request del Filament Resource.
 *
 * AUTO-CÓDIGO: PROY-{AÑO}-{NUMERO_5}, ej: PROY-2026-00001.
 * Numeración por año — cada año reinicia el contador. Bajo
 * concurrencia, la búsqueda del último número va con lockForUpdate.
 *
 * SNAPSHOT DE PRECIOS: los renglones congelan el precio_venta_cache
 * de la ficha al ser agregados. Cambios futuros en las fichas NO
 * afectan cotizaciones existentes — defensa de integridad comercial.
 *
 * ESTADOS (ver enum EstadoProyecto):
 *  - borrador (default): editable libremente
 *  - enviada: se congela para edición de renglones
 *  - aprobada/rechazada/vencida: terminales, solo lectura
 *
 * CACHE DE TOTALES: subtotal_cache + isv_cache + total_cache se
 * recalculan vía CalcularPrecioProyectoService. Idempotente.
 *
 * AUDITORÍA: cambios de estado, montos, fechas se loguean en
 * activitylog para trazabilidad completa.
 *
 * @property int $id
 * @property string $codigo
 * @property int $zona_id
 * @property int $cliente_id
 * @property string $nombre
 * @property string|null $descripcion
 * @property string $direccion_obra
 * @property Carbon $fecha_emision
 * @property Carbon $fecha_validez
 * @property EstadoProyecto $estado
 * @property string $moneda
 * @property bool $aplica_isv
 * @property string $isv_porcentaje
 * @property string|null $notas
 * @property string $subtotal_cache
 * @property string $isv_cache
 * @property string $total_cache
 * @property string|null $anticipo_monto
 * @property Carbon|null $anticipo_fecha
 * @property bool $anticipo_recibido
 * @property ModoPlazo|null $modo_plazo
 * @property int|null $plazo_dias
 * @property Carbon|null $fecha_inicio
 * @property Carbon|null $fecha_fin_estimada
 * @property Carbon|null $fecha_fin_real
 * @property string|null $motivo_pausa
 * @property string|null $motivo_cancelacion
 * @property string $avance_fisico_cache
 * @property Carbon|null $precio_calculado_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Zona $zona
 * @property-read Cliente $cliente
 * @property-read Collection<int, ProyectoRenglon> $renglones
 * @property-read Collection<int, ProyectoActividad> $actividades
 */
class Proyecto extends Model
{
    /** @use HasFactory<ProyectoFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'zona_id',
        'cliente_id',
        'nombre',
        'descripcion',
        'direccion_obra',
        'fecha_emision',
        'fecha_validez',
        'estado',
        'moneda',
        'aplica_isv',
        'isv_porcentaje',
        'notas',
        'subtotal_cache',
        'isv_cache',
        'total_cache',
        'anticipo_monto',
        'anticipo_fecha',
        'anticipo_recibido',
        'modo_plazo',
        'plazo_dias',
        'fecha_inicio',
        'fecha_fin_estimada',
        'fecha_fin_real',
        'motivo_pausa',
        'motivo_cancelacion',
        'avance_fisico_cache',
        'precio_calculado_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_emision'       => 'date',
            'fecha_validez'       => 'date',
            'estado'              => EstadoProyecto::class,
            'aplica_isv'          => 'boolean',
            'isv_porcentaje'      => 'decimal:2',
            'subtotal_cache'      => 'decimal:2',
            'isv_cache'           => 'decimal:2',
            'total_cache'         => 'decimal:2',
            'anticipo_monto'      => 'decimal:2',
            'anticipo_fecha'      => 'date',
            'anticipo_recibido'   => 'boolean',
            'modo_plazo'          => ModoPlazo::class,
            'plazo_dias'          => 'integer',
            'fecha_inicio'        => 'date',
            'fecha_fin_estimada'  => 'date',
            'fecha_fin_real'      => 'date',
            'avance_fisico_cache' => 'decimal:2',
            'precio_calculado_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'codigo',
                'cliente_id',
                'nombre',
                'estado',
                'fecha_emision',
                'fecha_validez',
                'aplica_isv',
                'isv_porcentaje',
                'subtotal_cache',
                'total_cache',
                'anticipo_monto',
                'anticipo_recibido',
                'modo_plazo',
                'plazo_dias',
                'fecha_inicio',
                'fecha_fin_estimada',
                'fecha_fin_real',
                'motivo_pausa',
                'motivo_cancelacion',
                'avance_fisico_cache',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Proyecto {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (Proyecto $proyecto): void {
            if (empty($proyecto->codigo)) {
                $anio = ($proyecto->fecha_emision instanceof Carbon)
                    ? $proyecto->fecha_emision->year
                    : (int) now()->year;

                $proyecto->codigo = self::generarCodigoSiguiente($anio);
            }
        });
    }

    /**
     * Genera el siguiente código secuencial PROY-{AÑO}-{NUMERO_5}.
     *
     * El contador se reinicia cada año: PROY-2026-00001 vs PROY-2027-00001.
     *
     * Concurrencia: la búsqueda va con lockForUpdate dentro de transacción
     * para serializar inserciones concurrentes en el mismo año.
     */
    public static function generarCodigoSiguiente(int $anio): string
    {
        $patron = "PROY-{$anio}-";

        return DB::transaction(static function () use ($patron): string {
            $ultimo = self::query()
                ->withTrashed()
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

    protected function direccionObra(): Attribute
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

    protected function motivoPausa(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    protected function motivoCancelacion(): Attribute
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
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * Encargados responsables de la obra (rol encargado_obra) — VARIOS,
     * para cubrir ausencias. Piden material y confirman recepciones; el
     * scoping "solo mis obras" usa esta relación.
     *
     * @return BelongsToMany<User, $this>
     */
    public function encargados(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'proyecto_encargados')->withTimestamps();
    }

    /**
     * ¿El usuario es encargado de ESTA obra?
     */
    public function esEncargado(User $user): bool
    {
        return $this->encargados()->whereKey($user->id)->exists();
    }

    /**
     * @return HasMany<ProyectoRenglon, $this>
     */
    public function renglones(): HasMany
    {
        return $this->hasMany(ProyectoRenglon::class)->orderBy('orden');
    }

    /**
     * @return HasMany<ProyectoActividad, $this>
     */
    public function actividades(): HasMany
    {
        return $this->hasMany(ProyectoActividad::class)->orderBy('orden');
    }

    // ─── Scopes ────────────────────────────────────────────────────

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
    public function scopeDeCliente(Builder $query, int $clienteId): Builder
    {
        return $query->where('cliente_id', $clienteId);
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeConEstado(Builder $query, EstadoProyecto $estado): Builder
    {
        return $query->where('estado', $estado->value);
    }

    /**
     * Proyectos enviados pero ya vencidos (fecha_validez < hoy y estado=enviada).
     * Usado por el Job que marca automáticamente como vencidos.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeEnviadosVencidos(Builder $query): Builder
    {
        return $query
            ->where('estado', EstadoProyecto::Enviada->value)
            ->whereDate('fecha_validez', '<', now()->toDateString());
    }

    /**
     * Proyectos cuyo cache de totales puede estar stale: precio_calculado_at
     * es nulo o es anterior a la última actualización de algún renglón
     * o de alguna ficha referenciada por renglones.
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
                        ->from('proyecto_renglones')
                        ->whereColumn('proyecto_renglones.proyecto_id', 'proyectos.id')
                        ->whereColumn('proyecto_renglones.updated_at', '>', 'proyectos.precio_calculado_at');
                });
        });
    }

    // ─── Helpers de estado ─────────────────────────────────────────

    /**
     * ¿Está vencida en este momento? (combina fecha + estado).
     */
    public function estaVencida(): bool
    {
        if ($this->estado === EstadoProyecto::Vencida) {
            return true;
        }

        return $this->estado === EstadoProyecto::Enviada
            && $this->fecha_validez->isPast();
    }

    // ─── Helpers de ejecución (plazo y avance) ─────────────────────

    /**
     * Días transcurridos de obra desde la fecha de inicio hasta hoy
     * (o hasta la fecha de fin real si ya terminó). Null si no ha
     * arrancado.
     */
    public function diasTranscurridos(): ?int
    {
        if ($this->fecha_inicio === null) {
            return null;
        }

        $hasta = $this->fecha_fin_real ?? Carbon::today();

        return (int) round($this->fecha_inicio->diffInDays($hasta));
    }

    /**
     * Días que faltan para la fecha de fin estimada. Negativo si ya
     * pasó (atrasado). Null si no hay fin estimada.
     */
    public function diasRestantes(): ?int
    {
        if ($this->fecha_fin_estimada === null) {
            return null;
        }

        return (int) round(Carbon::today()->diffInDays($this->fecha_fin_estimada));
    }

    /**
     * Porcentaje de TIEMPO consumido del plazo (0..100, recortado).
     * Se mide sobre el lapso calendario inicio → fin estimada, por lo
     * que funciona igual para plazo en días calendario o hábiles.
     * Null si no hay inicio o fin estimada.
     */
    public function porcentajeTiempo(): ?float
    {
        if ($this->fecha_inicio === null || $this->fecha_fin_estimada === null) {
            return null;
        }

        $total = (float) $this->fecha_inicio->diffInDays($this->fecha_fin_estimada);

        if ($total <= 0.0) {
            return null;
        }

        $hasta = $this->fecha_fin_real ?? Carbon::today();
        $transcurridos = (float) $this->fecha_inicio->diffInDays($hasta);

        return max(0.0, min(100.0, round(($transcurridos / $total) * 100, 2)));
    }

    /**
     * ¿La obra está atrasada? En ejecución o pausada, ya pasó la fecha
     * de fin estimada y el avance físico aún no llega al 100%.
     */
    public function estaAtrasado(): bool
    {
        if (! in_array($this->estado, [EstadoProyecto::EnEjecucion, EstadoProyecto::Pausada], strict: true)) {
            return false;
        }

        if ($this->fecha_fin_estimada === null) {
            return false;
        }

        return Carbon::today()->greaterThan($this->fecha_fin_estimada)
            && (float) $this->avance_fisico_cache < 100.0;
    }
}
