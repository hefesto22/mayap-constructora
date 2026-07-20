<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FaseMantenimiento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entrada de la bitácora de un mantenimiento — un diagnóstico o avance
 * con fecha y hora automáticas (`created_at`), la fase en la que quedó
 * la reparación, el detalle y quién lo registró.
 *
 * Solo se AGREGAN entradas (vía RegistrarAvanceMantenimientoService o
 * al finalizar); nunca se editan ni se borran: es el historial.
 *
 * @property int $id
 * @property int $mantenimiento_maquina_id
 * @property FaseMantenimiento $fase
 * @property string $detalle
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MantenimientoMaquina $mantenimiento
 */
class BitacoraMantenimiento extends Model
{
    protected $table = 'bitacoras_mantenimiento';

    /** @var list<string> */
    protected $fillable = [
        'mantenimiento_maquina_id',
        'fase',
        'detalle',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fase' => FaseMantenimiento::class,
        ];
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<MantenimientoMaquina, $this>
     */
    public function mantenimiento(): BelongsTo
    {
        return $this->belongsTo(MantenimientoMaquina::class, 'mantenimiento_maquina_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
