<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoMaquina;
use App\Enums\TipoMaquina;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\MaquinaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Máquina — unidad de maquinaria pesada. El `horometro_actual` es un saldo
 * que mueven los partes de trabajo (no se edita a mano). La `tarifa_hora` es
 * el costo por defecto; la asignación a obra puede pactar otra.
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
 * @property string $horometro_actual
 * @property string $tarifa_hora
 * @property string $jornada_horas
 * @property EstadoMaquina $estado
 * @property string|null $notas
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read AgendaMaquina|null $agendaHoyConfirmada
 */
class Maquina extends Model
{
    /** @use HasFactory<MaquinaFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'maquinas';

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
        'tarifa_hora',
        'jornada_horas',
        'estado',
        'notas',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'             => TipoMaquina::class,
            'estado'           => EstadoMaquina::class,
            'anio'             => 'integer',
            'horometro_actual' => 'decimal:2',
            'tarifa_hora'      => 'decimal:2',
            'jornada_horas'    => 'decimal:2',
            'activo'           => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'codigo', 'nombre', 'tipo', 'marca', 'modelo', 'anio', 'serie',
                'horometro_actual', 'tarifa_hora', 'jornada_horas', 'estado', 'activo',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Máquina {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

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
     * El agendado de HOY con llegada confirmada y SIN salida — la
     * evidencia de que la máquina está trabajando en una obra AHORA
     * (cuando el encargado confirma que terminó, deja de contar).
     *
     * @return HasOne<AgendaMaquina, $this>
     */
    public function agendaHoyConfirmada(): HasOne
    {
        return $this->hasOne(AgendaMaquina::class)
            ->whereDate('fecha', today())
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
     * Nombre de la obra donde trabaja hoy (null si no está trabajando).
     */
    public function obraDondeTrabajaHoy(): ?string
    {
        return $this->trabajandoHoy()
            ? $this->agendaHoyConfirmada?->proyecto->nombre
            : null;
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
}
