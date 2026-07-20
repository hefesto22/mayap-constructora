<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Periodicidad;
use App\Enums\TipoPago;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\EmpleadoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Empleado — personal para la planilla. El `tipo_pago` define cómo se calcula
 * su monto (jornal/salario/destajo) y `tarifa_base` su pago por día o período.
 *
 * AUTO-CÓDIGO: EMP-{NUMERO_5} global, generado en `creating` con lockForUpdate.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $identidad
 * @property string|null $cargo
 * @property TipoPago $tipo_pago
 * @property Periodicidad $periodicidad_pago
 * @property string $tarifa_base
 * @property string|null $notas
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Empleado extends Model
{
    /** @use HasFactory<EmpleadoFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'empleados';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'identidad',
        'cargo',
        'tipo_pago',
        'periodicidad_pago',
        'tarifa_base',
        'notas',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo_pago'         => TipoPago::class,
            'periodicidad_pago' => Periodicidad::class,
            'tarifa_base'       => 'decimal:2',
            'activo'            => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['codigo', 'nombre', 'identidad', 'cargo', 'tipo_pago', 'periodicidad_pago', 'tarifa_base', 'activo'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Empleado {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (Empleado $empleado): void {
            if (empty($empleado->codigo)) {
                $empleado->codigo = self::generarCodigoSiguiente();
            }
        });
    }

    public static function generarCodigoSiguiente(): string
    {
        $patron = 'EMP-';

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

    // ─── Mutators uppercase (no aplica a identidad, que es numérica) ─

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

    protected function cargo(): Attribute
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
     * Empleados de una frecuencia de pago (la planilla quincenal solo
     * ofrece quincenales, etc.). Null → sin filtro.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeDePeriodicidad(Builder $query, ?string $periodicidad): Builder
    {
        return $query->when(
            $periodicidad !== null && $periodicidad !== '',
            fn (Builder $q): Builder => $q->where('periodicidad_pago', $periodicidad),
        );
    }
}
