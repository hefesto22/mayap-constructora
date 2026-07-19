<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\CambioMantenimientoFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cambio de mantenimiento REALIZADO — bitácora pura: una fila por cada
 * vez que el taller ejecutó el plan (cambio de aceite, puntas...), con
 * las lecturas del momento. Nace únicamente vía
 * RegistrarCambioMantenimientoService; no se edita desde la UI.
 *
 * @property int $id
 * @property int $plan_mantenimiento_id
 * @property Carbon $fecha
 * @property string|null $horometro
 * @property string|null $kilometraje
 * @property string|null $notas
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PlanMantenimiento $plan
 * @property-read User|null $user
 */
class CambioMantenimiento extends Model
{
    /** @use HasFactory<CambioMantenimientoFactory> */
    use HasFactory;

    use HasUppercaseAttributes;

    protected $table = 'cambios_mantenimiento';

    /** @var list<string> */
    protected $fillable = [
        'plan_mantenimiento_id',
        'fecha',
        'horometro',
        'kilometraje',
        'notas',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha'       => 'date',
            'horometro'   => 'decimal:2',
            'kilometraje' => 'decimal:2',
        ];
    }

    protected function notas(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
    }

    /**
     * @return BelongsTo<PlanMantenimiento, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanMantenimiento::class, 'plan_mantenimiento_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
