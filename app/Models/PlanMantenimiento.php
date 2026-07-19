<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertaMantenimiento;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\PlanMantenimientoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Plan de mantenimiento preventivo de una máquina: "cambio de aceite
 * cada 250 horas o cada 3 meses", "puntas cada 400 horas", "aceite de
 * la volqueta cada 5,000 km"... Cada plan define SU intervalo en horas
 * de horómetro, km y/o días — LO QUE LLEGUE PRIMERO dispara la alerta.
 *
 * La línea base (fecha/horómetro/km del último cambio) se resetea vía
 * RegistrarCambioMantenimientoService, que además deja el historial en
 * `cambios_mantenimiento`. La alerta NUNCA se guarda: se deriva aquí.
 *
 * Dimensiones sin datos se ignoran: un plan por km en una máquina sin
 * kilometraje capturado simplemente no alerta por km (sí por las otras
 * frecuencias que tenga).
 *
 * @property int $id
 * @property int $maquina_id
 * @property string $nombre
 * @property string|null $frecuencia_horas
 * @property string|null $frecuencia_km
 * @property int|null $frecuencia_dias
 * @property Carbon $fecha_ultimo_cambio
 * @property string|null $horometro_ultimo_cambio
 * @property string|null $km_ultimo_cambio
 * @property string|null $ultimo_aviso_estado
 * @property bool $activo
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Maquina $maquina
 */
class PlanMantenimiento extends Model
{
    /** @use HasFactory<PlanMantenimientoFactory> */
    use HasFactory;

    use HasUppercaseAttributes;

    /** Umbral de "próximo": 90% del intervalo consumido. */
    public const float UMBRAL_PROXIMO = 0.9;

    protected $table = 'planes_mantenimiento';

    /** @var list<string> */
    protected $fillable = [
        'maquina_id',
        'nombre',
        'frecuencia_horas',
        'frecuencia_km',
        'frecuencia_dias',
        'fecha_ultimo_cambio',
        'horometro_ultimo_cambio',
        'km_ultimo_cambio',
        'ultimo_aviso_estado',
        'activo',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'frecuencia_horas'        => 'decimal:2',
            'frecuencia_km'           => 'decimal:2',
            'frecuencia_dias'         => 'integer',
            'fecha_ultimo_cambio'     => 'date',
            'horometro_ultimo_cambio' => 'decimal:2',
            'km_ultimo_cambio'        => 'decimal:2',
            'activo'                  => 'boolean',
        ];
    }

    protected function nombre(): Attribute
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
     * @return BelongsTo<Maquina, $this>
     */
    public function maquina(): BelongsTo
    {
        return $this->belongsTo(Maquina::class);
    }

    /**
     * @return HasMany<CambioMantenimiento, $this>
     */
    public function cambios(): HasMany
    {
        return $this->hasMany(CambioMantenimiento::class, 'plan_mantenimiento_id');
    }

    // ─── Alerta derivada ───────────────────────────────────────────

    /**
     * Nivel de alerta HOY: el peor de los frentes con datos (horas,
     * km, días). Sin ningún frente calculable → Al día.
     */
    public function estadoAlerta(): AlertaMantenimiento
    {
        $peor = max([0.0, ...array_values($this->ratiosDeUso())]);

        return match (true) {
            $peor >= 1.0                  => AlertaMantenimiento::Vencido,
            $peor >= self::UMBRAL_PROXIMO => AlertaMantenimiento::Proximo,
            default                       => AlertaMantenimiento::AlDia,
        };
    }

    /**
     * Fracción del intervalo consumida por frente (1.0 = tocaba hoy).
     * Solo incluye frentes con frecuencia definida Y línea base/lectura
     * actual disponibles.
     *
     * @return array<string, float>
     */
    public function ratiosDeUso(): array
    {
        $ratios = [];

        if ($this->frecuencia_horas !== null && $this->horometro_ultimo_cambio !== null) {
            $uso = max(0.0, (float) $this->maquina->horometro_actual - (float) $this->horometro_ultimo_cambio);
            $ratios['horas'] = $uso / (float) $this->frecuencia_horas;
        }

        if (
            $this->frecuencia_km !== null
            && $this->km_ultimo_cambio !== null
            && $this->maquina->kilometraje_actual !== null
        ) {
            $uso = max(0.0, (float) $this->maquina->kilometraje_actual - (float) $this->km_ultimo_cambio);
            $ratios['km'] = $uso / (float) $this->frecuencia_km;
        }

        if ($this->frecuencia_dias !== null) {
            $dias = max(0, (int) $this->fecha_ultimo_cambio->diffInDays(today(), false));
            $ratios['dias'] = $dias / $this->frecuencia_dias;
        }

        return $ratios;
    }

    /**
     * Resumen humano del uso vs. intervalo, para tooltips y avisos:
     * "230 de 250 h · 45 de 90 días".
     */
    public function usoResumen(): string
    {
        $partes = [];

        if ($this->frecuencia_horas !== null && $this->horometro_ultimo_cambio !== null) {
            $uso = max(0.0, (float) $this->maquina->horometro_actual - (float) $this->horometro_ultimo_cambio);
            $partes[] = number_format($uso, 0).' de '.number_format((float) $this->frecuencia_horas, 0).' h';
        }

        if (
            $this->frecuencia_km !== null
            && $this->km_ultimo_cambio !== null
            && $this->maquina->kilometraje_actual !== null
        ) {
            $uso = max(0.0, (float) $this->maquina->kilometraje_actual - (float) $this->km_ultimo_cambio);
            $partes[] = number_format($uso, 0).' de '.number_format((float) $this->frecuencia_km, 0).' km';
        }

        if ($this->frecuencia_dias !== null) {
            $dias = max(0, (int) $this->fecha_ultimo_cambio->diffInDays(today(), false));
            $partes[] = "{$dias} de {$this->frecuencia_dias} días";
        }

        return $partes === []
            ? 'Sin lecturas para calcular'
            : implode(' · ', $partes);
    }

    /**
     * Descripción del intervalo pactado: "CADA 250 H / 5,000 KM / 90 DÍAS".
     */
    public function intervaloResumen(): string
    {
        $partes = [];

        if ($this->frecuencia_horas !== null) {
            $partes[] = number_format((float) $this->frecuencia_horas, 0).' h';
        }

        if ($this->frecuencia_km !== null) {
            $partes[] = number_format((float) $this->frecuencia_km, 0).' km';
        }

        if ($this->frecuencia_dias !== null) {
            $partes[] = "{$this->frecuencia_dias} días";
        }

        return 'cada '.implode(' / ', $partes);
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
