<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CondicionPago;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\ProveedorFactory;
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
 * Proveedor — entidad compartida (compras de bodega, repuestos de
 * maquinaria a futuro). Lleva condición de pago para las cuentas por pagar.
 *
 * AUTO-CÓDIGO: PRV-{NUMERO_5} global, generado en `creating` con
 * lockForUpdate (mismo patrón que Cliente/Bodega).
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $rtn
 * @property string|null $telefono
 * @property string|null $email
 * @property string|null $direccion
 * @property string|null $ciudad
 * @property CondicionPago $condicion_pago
 * @property int $dias_credito
 * @property string|null $notas
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Proveedor extends Model
{
    /** @use HasFactory<ProveedorFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'proveedores';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'rtn',
        'telefono',
        'email',
        'direccion',
        'ciudad',
        'condicion_pago',
        'dias_credito',
        'notas',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condicion_pago' => CondicionPago::class,
            'dias_credito'   => 'integer',
            'activo'         => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'codigo', 'nombre', 'rtn', 'telefono', 'email',
                'direccion', 'ciudad', 'condicion_pago', 'dias_credito', 'activo',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => "Proveedor {$eventName}");
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (Proveedor $proveedor): void {
            if (empty($proveedor->codigo)) {
                $proveedor->codigo = self::generarCodigoSiguiente();
            }
        });
    }

    /**
     * Genera el siguiente código secuencial PRV-00001, PRV-00002, ...
     *
     * Concurrencia: lockForUpdate dentro de transacción serializa
     * creaciones simultáneas.
     */
    public static function generarCodigoSiguiente(): string
    {
        $patron = 'PRV-';

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

    protected function ciudad(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    protected function direccion(): Attribute
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
}
