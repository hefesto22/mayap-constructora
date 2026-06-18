<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoRequisicion;
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
 * @property int|null $solicitante_id
 * @property Carbon $fecha_solicitud
 * @property Carbon $fecha_necesaria
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
        'solicitante_id',
        'fecha_solicitud',
        'fecha_necesaria',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'          => EstadoRequisicion::class,
            'fecha_solicitud' => 'date',
            'fecha_necesaria' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'proyecto_id', 'estado', 'solicitante_id', 'fecha_necesaria', 'notas'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Requisición {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

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

    // ─── Relaciones ────────────────────────────────────────────────

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
