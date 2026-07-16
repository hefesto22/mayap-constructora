<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoSolicitudMaquina;
use App\Enums\PrioridadSolicitud;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Solicitud de maquinaria — el encargado de obra pide "esta máquina para
 * tal día a tal hora". La agenda decide al crearla: disponible → nace
 * Agendada con su agendado vinculado; ocupada → Pendiente y el rol
 * maquinaria la resuelve. Nunca se borra: es historial del proyecto
 * (quién pidió, cuándo, en qué quedó y por qué).
 *
 * AUTO-CÓDIGO: SOLMAQ-{AÑO}-{NUMERO_5} con contador que se reinicia por año.
 *
 * @property int $id
 * @property string $codigo
 * @property int $proyecto_id
 * @property int $maquina_id
 * @property Carbon $fecha_necesaria
 * @property Carbon|null $fecha_hasta
 * @property string $hora_llegada
 * @property EstadoSolicitudMaquina $estado
 * @property PrioridadSolicitud $prioridad
 * @property string|null $notas
 * @property int|null $solicitante_id
 * @property int|null $agenda_maquina_id
 * @property int|null $resuelta_por_id
 * @property Carbon|null $resuelta_at
 * @property string|null $motivo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proyecto $proyecto
 * @property-read Maquina $maquina
 * @property-read User|null $solicitante
 * @property-read User|null $resueltaPor
 * @property-read AgendaMaquina|null $agendado
 */
class SolicitudMaquina extends Model
{
    protected $table = 'solicitudes_maquina';

    /** @var list<string> */
    protected $fillable = [
        'codigo',
        'proyecto_id',
        'maquina_id',
        'fecha_necesaria',
        'fecha_hasta',
        'hora_llegada',
        'estado',
        'prioridad',
        'notas',
        'solicitante_id',
        'agenda_maquina_id',
        'resuelta_por_id',
        'resuelta_at',
        'motivo',
    ];

    /**
     * Hora de llegada pedida, en 12 horas ('7:00 AM') — el formato de la
     * constructora.
     */
    public function horaLlegada12(): ?string
    {
        return $this->hora_llegada !== null
            ? Carbon::parse((string) $this->hora_llegada)->format('g:i A')
            : null;
    }

    /**
     * El rango pedido, legible: '17/07/2026' o 'del 17/07 al 19/07/2026'.
     */
    public function rangoParaEl(): string
    {
        if ($this->fecha_hasta === null || $this->fecha_hasta->equalTo($this->fecha_necesaria)) {
            return $this->fecha_necesaria->format('d/m/Y');
        }

        return 'del '.$this->fecha_necesaria->format('d/m').' al '.$this->fecha_hasta->format('d/m/Y');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado'          => EstadoSolicitudMaquina::class,
            'prioridad'       => PrioridadSolicitud::class,
            'fecha_necesaria' => 'date',
            'fecha_hasta'     => 'date',
            'resuelta_at'     => 'datetime',
        ];
    }

    // ─── Lifecycle: auto-generación de código ──────────────────────

    protected static function booted(): void
    {
        static::creating(static function (SolicitudMaquina $solicitud): void {
            if (empty($solicitud->codigo)) {
                $solicitud->codigo = self::generarCodigoSiguiente((int) now()->year);
            }
        });
    }

    /**
     * Genera el siguiente código secuencial SOLMAQ-{AÑO}-{NUMERO_5}.
     */
    public static function generarCodigoSiguiente(int $anio): string
    {
        $patron = "SOLMAQ-{$anio}-";

        return DB::transaction(static function () use ($patron): string {
            $ultimo = self::query()
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

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /**
     * @return BelongsTo<Maquina, $this>
     */
    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resueltaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelta_por_id');
    }

    /**
     * El agendado que la cumplió (null mientras está pendiente o si fue
     * rechazada).
     *
     * @return BelongsTo<AgendaMaquina, $this>
     */
    public function agendado(): BelongsTo
    {
        return $this->belongsTo(AgendaMaquina::class, 'agenda_maquina_id');
    }
}
