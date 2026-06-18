<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoRequisicion;
use Database\Factories\RequisicionTransicionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Transición de estado de una requisición — renglón inmutable de la
 * bitácora de auditoría. Responde "quién pasó la requisición de qué estado
 * a cuál, cuándo, y con qué nota".
 *
 * No lleva LogsActivity porque ES, en sí mismo, el registro de auditoría
 * del flujo. La crea el Service dentro de la misma transacción que cambia
 * el estado de la requisición. estado_origen es null en la creación.
 *
 * @property int $id
 * @property int $requisicion_id
 * @property EstadoRequisicion|null $estado_origen
 * @property EstadoRequisicion $estado_destino
 * @property int|null $user_id
 * @property string|null $nota
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Requisicion $requisicion
 * @property-read User|null $user
 */
class RequisicionTransicion extends Model
{
    /** @use HasFactory<RequisicionTransicionFactory> */
    use HasFactory;

    protected $table = 'requisicion_transiciones';

    /** @var list<string> */
    protected $fillable = [
        'requisicion_id',
        'estado_origen',
        'estado_destino',
        'user_id',
        'nota',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado_origen'  => EstadoRequisicion::class,
            'estado_destino' => EstadoRequisicion::class,
        ];
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<Requisicion, $this>
     */
    public function requisicion(): BelongsTo
    {
        return $this->belongsTo(Requisicion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
