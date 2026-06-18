<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cliente — entidad de negocio que recibe cotizaciones (Proyectos).
 *
 * Un cliente puede tener múltiples proyectos a lo largo del tiempo.
 * Sus datos de contacto NO se snapshot en los proyectos: si actualiza
 * teléfono o dirección, las cotizaciones existentes apuntan al mismo
 * cliente y muestran datos actualizados (correcto, no afecta monto).
 *
 * AUTO-CÓDIGO: CLI-{NUMERO_5_DIGITOS}, ej: CLI-00001.
 * Generado al crear si viene vacío. Bajo concurrencia, va en
 * transacción con lockForUpdate.
 *
 * MUTATORS UPPERCASE: nombre y código se uppercase automáticamente
 * para consistencia con el resto del sistema.
 *
 * RTN OPCIONAL: clientes individuales pueden no tener RTN registrado.
 * Cuando se proporciona, debe tener exactamente 14 dígitos (validado
 * por CHECK constraint en DB y Form Request en la UI). Único parcial
 * en DB: permite múltiples clientes sin RTN, rechaza duplicados
 * cuando hay valor.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $rtn
 * @property string|null $telefono
 * @property string|null $email
 * @property string|null $direccion
 * @property string|null $ciudad
 * @property string|null $notas
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Proyecto> $proyectos
 */
class Cliente extends Model
{
    /** @use HasFactory<ClienteFactory> */
    use HasFactory;

    use HasUppercaseAttributes;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'nombre',
        'rtn',
        'telefono',
        'email',
        'direccion',
        'ciudad',
        'notas',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (Cliente $cliente): void {
            if (empty($cliente->codigo)) {
                $cliente->codigo = self::generarCodigoSiguiente();
            }
        });
    }

    /**
     * Genera el siguiente código secuencial CLI-{NUMERO_5}.
     *
     * Concurrencia: lockForUpdate dentro de transacción para que dos
     * requests simultáneos creando clientes se serialicen.
     *
     * Considera SoftDeletes: el conteo se hace sobre el último código
     * usado (incluyendo soft-deleted) para no reciclar números.
     */
    public static function generarCodigoSiguiente(): string
    {
        $patron = 'CLI-';

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

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return HasMany<Proyecto, $this>
     */
    public function proyectos(): HasMany
    {
        return $this->hasMany(Proyecto::class);
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
     * Búsqueda por nombre, RTN, email o teléfono — útil para autocompletados.
     *
     * Usa `ILIKE` (case-insensitive de Postgres) para que la búsqueda
     * funcione contra columnas con casing mixto: el `nombre` está
     * uppercase por mutator, pero el `email` queda lowercase porque
     * los proveedores de email son case-insensitive a nivel RFC.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeBuscar(Builder $query, string $termino): Builder
    {
        $termino = trim($termino);

        if ($termino === '') {
            return $query;
        }

        $like = '%'.$termino.'%';

        return $query->where(static function (Builder $q) use ($like): void {
            $q->where('nombre', 'ilike', $like)
                ->orWhere('rtn', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                ->orWhere('telefono', 'ilike', $like);
        });
    }

    // ─── Accessors útiles ──────────────────────────────────────────

    /**
     * Etiqueta corta para selects: "CLI-00001 · Juan Pérez · 0801...".
     */
    public function getEtiquetaAttribute(): string
    {
        $partes = [$this->codigo, $this->nombre];

        if (! empty($this->rtn)) {
            $partes[] = $this->rtn;
        }

        return implode(' · ', $partes);
    }
}
