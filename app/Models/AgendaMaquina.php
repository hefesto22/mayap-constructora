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
 * Agenda de máquina — compromiso FUTURO simple: "la máquina llega a las X
 * a la obra Y el día Z".
 *
 * Sin horas estimadas (decisión Mauricio 2026-07-14): nunca se sabe
 * cuánto trabajará — las horas REALES las dice el parte de trabajo. La
 * hora de entrada es la del aviso "confirma la llegada". El calendario
 * pinta azul (agenda) y verde (parte real).
 *
 * Las validaciones de negocio (choque con mantenimiento, duplicados,
 * fechas pasadas) viven en AgendarMaquinaService — única puerta de
 * creación de agenda.
 *
 * @property int $id
 * @property int $maquina_id
 * @property int $proyecto_id
 * @property Carbon $fecha
 * @property string|null $hora_entrada
 * @property string|null $notas
 * @property int|null $user_id
 * @property Carbon|null $aviso_llegada_at
 * @property Carbon|null $llegada_confirmada_at
 * @property int|null $llegada_confirmada_por
 * @property Carbon|null $salida_confirmada_at
 * @property int|null $salida_confirmada_por
 * @property Carbon|null $no_llego_at
 * @property int|null $no_llego_por
 * @property string|null $no_llego_motivo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Maquina $maquina
 * @property-read Proyecto $proyecto
 * @property-read User|null $user
 * @property-read User|null $confirmadaPor
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
        'hora_entrada',
        'notas',
        'user_id',
        'aviso_llegada_at',
        'llegada_confirmada_at',
        'llegada_confirmada_por',
        'salida_confirmada_at',
        'salida_confirmada_por',
        'no_llego_at',
        'no_llego_por',
        'no_llego_motivo',
    ];

    /**
     * Hora de entrada corta para la UI ('07:00') — la DB guarda TIME.
     */
    public function horaEntradaCorta(): ?string
    {
        return $this->hora_entrada !== null
            ? substr((string) $this->hora_entrada, 0, 5)
            : null;
    }

    /**
     * Hora de llegada en 12 horas ('7:00 AM') — en la constructora se
     * maneja AM/PM, no el formato de 24 horas.
     */
    public function horaEntrada12(): ?string
    {
        return $this->hora_entrada !== null
            ? Carbon::parse((string) $this->hora_entrada)->format('g:i A')
            : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha'                 => 'date',
            'aviso_llegada_at'      => 'datetime',
            'llegada_confirmada_at' => 'datetime',
            'salida_confirmada_at'  => 'datetime',
            'no_llego_at'           => 'datetime',
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

    /**
     * Quién confirmó que la máquina llegó a la obra.
     *
     * @return BelongsTo<User, $this>
     */
    public function confirmadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'llegada_confirmada_por');
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
