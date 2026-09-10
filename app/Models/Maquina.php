<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoAsignacion;
use App\Enums\EstadoMantenimiento;
use App\Enums\EstadoMaquina;
use App\Enums\ModalidadTrabajo;
use App\Enums\TipoMaquina;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\MaquinaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Override;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Máquina — unidad de maquinaria pesada. El `horometro_actual` es un saldo
 * que mueven los partes de trabajo (no se edita a mano). La `tarifa_hora` es
 * el costo por defecto; la asignación a obra puede pactar otra.
 *
 * MODALIDAD DE TRABAJO (decisión Mauricio 2026-07-20): pesada por horas,
 * pick-ups por kilometraje, volquetas por viajes, camiones por flete —
 * el default que sugiere cada parte de trabajo. `tarifa_viaje` y
 * `tarifa_km` completan el catálogo para cotizar rentas por esas unidades.
 *
 * AUTO-CÓDIGO: MAQ-{NUMERO_5} global, generado en `creating` con
 * lockForUpdate (mismo patrón que Proveedor/Bodega).
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property TipoMaquina $tipo
 * @property string|null $marca
 * @property string|null $modelo
 * @property int|null $anio
 * @property string|null $serie
 * @property numeric-string $horometro_actual
 * @property string|null $kilometraje_actual
 * @property string $tarifa_hora
 * @property numeric-string $horas_dia_renta
 * @property numeric-string|null $tarifa_dia
 * @property numeric-string|null $tarifa_semana
 * @property numeric-string|null $tarifa_mes
 * @property numeric-string|null $tarifa_flete
 * @property ModalidadTrabajo $modalidad_trabajo
 * @property int|null $operador_habitual_id
 * @property string|null $tarifa_viaje
 * @property string|null $tarifa_km
 * @property EstadoMaquina $estado
 * @property string|null $notas
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Operador|null $operadorHabitual
 * @property-read AgendaMaquina|null $agendaHoyConfirmada
 * @property-read AsignacionMaquina|null $asignacionActiva
 * @property-read MantenimientoMaquina|null $mantenimientoEnProceso
 * @property-read Collection<int, PlanMantenimiento> $planesMantenimiento
 */
class Maquina extends Model
{
    /** @use HasFactory<MaquinaFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'maquinas';

    /**
     * Default en memoria (espejo del default de la DB) — misma lección
     * que Compra.categoria (2026-07-20).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'modalidad_trabajo' => 'horas',
    ];

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'marca',
        'modelo',
        'anio',
        'serie',
        'horometro_actual',
        'kilometraje_actual',
        'tarifa_hora',
        'horas_dia_renta',
        'litros_por_hora',
        'operador_habitual_id',
        'tarifa_dia',
        'tarifa_semana',
        'tarifa_mes',
        'tarifa_flete',
        'modalidad_trabajo',
        'tarifa_viaje',
        'tarifa_km',
        'estado',
        'notas',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'tipo'               => TipoMaquina::class,
            'estado'             => EstadoMaquina::class,
            'modalidad_trabajo'  => ModalidadTrabajo::class,
            'anio'               => 'integer',
            'horometro_actual'   => 'decimal:2',
            'kilometraje_actual' => 'decimal:2',
            'tarifa_hora'        => 'decimal:2',
            'horas_dia_renta'    => 'decimal:2',
            'litros_por_hora'    => 'decimal:2',
            'tarifa_dia'         => 'decimal:2',
            'tarifa_semana'      => 'decimal:2',
            'tarifa_mes'         => 'decimal:2',
            'tarifa_flete'       => 'decimal:2',
            'tarifa_viaje'       => 'decimal:2',
            'tarifa_km'          => 'decimal:2',
            'activo'             => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'codigo', 'nombre', 'tipo', 'marca', 'modelo', 'anio', 'serie',
                'horometro_actual', 'kilometraje_actual', 'tarifa_hora', 'horas_dia_renta',
                'tarifa_dia', 'tarifa_semana', 'tarifa_mes', 'tarifa_flete',
                'modalidad_trabajo', 'tarifa_viaje', 'tarifa_km', 'estado', 'activo',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Máquina {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    #[Override]
    protected static function booted(): void
    {
        static::creating(static function (Maquina $maquina): void {
            if (empty($maquina->codigo)) {
                $maquina->codigo = self::generarCodigoSiguiente();
            }
        });
    }

    /**
     * Genera el siguiente código secuencial MAQ-00001, MAQ-00002, ...
     *
     * Concurrencia: lockForUpdate dentro de transacción serializa
     * creaciones simultáneas.
     */
    public static function generarCodigoSiguiente(): string
    {
        $patron = 'MAQ-';

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

    protected function marca(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    protected function modelo(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    protected function serie(): Attribute
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

    // ─── Trabajando hoy (estado VISUAL, no del ciclo de vida) ──────

    /**
     * La ESTADÍA ABIERTA: el agendado con llegada confirmada y sin
     * salida — la evidencia de que la máquina está en una obra AHORA.
     *
     * SIN filtro de fecha (2026-09-05): la estadía es abierta, y una
     * máquina que llegó el lunes sigue en la obra el jueves aunque su
     * agendado sea del lunes. Con el filtro de "hoy" toda máquina que
     * pasara de un día se leía como si no estuviera trabajando.
     *
     * @return HasOne<AgendaMaquina, $this>
     */
    public function agendaHoyConfirmada(): HasOne
    {
        return $this->hasOne(AgendaMaquina::class)
            ->whereNotNull('llegada_confirmada_at')
            ->whereNull('salida_confirmada_at')
            ->latest('llegada_confirmada_at');
    }

    /**
     * ¿Está trabajando en una obra AHORA? (decisión Mauricio 2026-07-15):
     * llegada confirmada HOY → se muestra "Trabajando" todo el día y
     * mañana vuelve sola a su estado normal. Es un estado VISUAL derivado,
     * no toca el ciclo de vida real — taller y baja siempre ganan.
     */
    public function trabajandoHoy(): bool
    {
        return in_array($this->estado, [EstadoMaquina::Disponible, EstadoMaquina::Asignada], strict: true)
            && $this->agendaHoyConfirmada !== null;
    }

    /**
     * Nombre de la obra DONDE ESTÁ la máquina (null si no está en
     * ninguna). Vale para estadías de un día o de tres semanas.
     */
    public function obraDondeTrabajaHoy(): ?string
    {
        return $this->trabajandoHoy()
            ? $this->agendaHoyConfirmada?->proyecto->nombre
            : null;
    }

    // ─── Asignación a obra ─────────────────────────────────────────

    /**
     * Todo lo gastado en reparar esta máquina, de todas sus averías: es
     * su historial de costo, independiente de la obra donde ocurrieron
     * (decisión Mauricio 2026-09-05).
     *
     * @return HasMany<GastoMantenimiento, $this>
     */
    public function gastosMantenimiento(): HasMany
    {
        return $this->hasMany(GastoMantenimiento::class);
    }

    /**
     * El operador habitual de esta máquina — casi siempre es el mismo
     * señor en la misma máquina, así que el parte del día lo trae puesto
     * (2026-09-05). Puede ser de planilla o externo; eso lo dice él.
     *
     * @return BelongsTo<Operador, $this>
     */
    public function operadorHabitual(): BelongsTo
    {
        return $this->belongsTo(Operador::class, 'operador_habitual_id');
    }

    /**
     * La asignación ABIERTA de la máquina — a qué obra está comprometida
     * y con qué tarifa. Única fuente para saber por qué está "Asignada"
     * y la que se cierra al devolverla al parque.
     *
     * Null con estado Asignada = estado HUÉRFANO (obra cerrada por una
     * vía vieja, dato migrado): "Liberar de la obra" la destraba igual.
     *
     * @return HasOne<AsignacionMaquina, $this>
     */
    public function asignacionActiva(): HasOne
    {
        return $this->hasOne(AsignacionMaquina::class)
            ->where('estado', EstadoAsignacion::Activa->value)
            ->latest('fecha_inicio');
    }

    // ─── Mantenimiento correctivo (taller) ─────────────────────────

    /**
     * La reparación ABIERTA de la máquina — única fuente para saber por
     * qué está en el taller y la que se cierra al devolverla al parque.
     *
     * Null con estado Mantenimiento = estado HUÉRFANO: la máquina figura
     * en el taller sin expediente abierto (antes quedaba trabada para
     * siempre; hoy "Marcar como reparada" la libera igual).
     *
     * @return HasOne<MantenimientoMaquina, $this>
     */
    public function mantenimientoEnProceso(): HasOne
    {
        return $this->hasOne(MantenimientoMaquina::class)
            ->where('estado', EstadoMantenimiento::EnProceso->value)
            ->latest('fecha_inicio');
    }

    // ─── Mantenimiento preventivo ──────────────────────────────────

    /**
     * @return HasMany<PlanMantenimiento, $this>
     */
    public function planesMantenimiento(): HasMany
    {
        return $this->hasMany(PlanMantenimiento::class);
    }

    /**
     * El plan ACTIVO con la peor alerta — alimenta el badge de
     * mantenimiento del listado. Null si no hay planes activos.
     *
     * Enlaza cada plan de vuelta a ESTA instancia antes de calcular,
     * para que estadoAlerta() no dispare N+1 al leer el horómetro/km.
     */
    public function planPeorAlerta(): ?PlanMantenimiento
    {
        return $this->planesMantenimiento
            ->filter(fn (PlanMantenimiento $plan): bool => $plan->activo)
            ->each(fn (PlanMantenimiento $plan) => $plan->setRelation('maquina', $this))
            ->sortByDesc(fn (PlanMantenimiento $plan): int => $plan->estadoAlerta()->severidad())
            ->first();
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
     * Máquinas libres para asignar a una obra.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeDisponibles(Builder $query): Builder
    {
        return $query->where('estado', EstadoMaquina::Disponible->value);
    }

    /**
     * Máquinas que se pueden COMPROMETER a una obra: activas en el
     * catálogo y no dadas de baja. ÚNICA puerta de los selectores de
     * agendar, solicitar y elegir sustituta.
     *
     * Antes cada pantalla filtraba a su manera y tres de ellas miraban
     * solo el estado "de baja" — que ningún flujo del sistema asigna —,
     * así que la máquina apagada con el toggle "activa" seguía saliendo
     * al agendar y al pedirla desde la obra (corregido 2026-08-07).
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeAgendables(Builder $query): Builder
    {
        return $query->activas()->whereNot('estado', EstadoMaquina::Baja->value);
    }
}
