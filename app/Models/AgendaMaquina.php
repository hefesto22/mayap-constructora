<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AgendaMaquinaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Agenda de máquina — compromiso FUTURO por día y horas.
 *
 * "El 15 la excavadora va 4 horas a Las Palmas": la agenda dice a dónde
 * VA a ir la máquina; el parte de trabajo dice cuánto TRABAJÓ de verdad.
 * El calendario los pinta azul (agenda) y verde (parte); el hueco entre
 * ambos = máquina libre para alquilar.
 *
 * Las validaciones de negocio (choque con mantenimiento, duplicados,
 * fechas pasadas) viven en AgendarMaquinaService — única puerta de
 * creación de agenda.
 *
 * @property int $id
 * @property int $maquina_id
 * @property int $proyecto_id
 * @property Carbon $fecha
 * @property string $horas_previstas
 * @property string|null $notas
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Maquina $maquina
 * @property-read Proyecto $proyecto
 * @property-read User|null $user
 */
class AgendaMaquina extends Model
{
    /** @use HasFactory<AgendaMaquinaFactory> */
    use HasFactory;

    protected $table = 'agenda_maquina';

    /** @var list<string> */
    protected $fillable = [
        'maquina_id',
        'proyecto_id',
        'fecha',
        'horas_previstas',
        'notas',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha'           => 'date',
            'horas_previstas' => 'decimal:2',
        ];
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<Maquina, $this>
     */
    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class);
    }

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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * Compromisos de hoy en adelante.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->whereDate('fecha', '>=', today());
    }
}
