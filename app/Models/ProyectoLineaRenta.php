<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UnidadRenta;
use App\Models\Concerns\HasUppercaseAttributes;
use Database\Factories\ProyectoLineaRentaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Línea de renta de un Proyecto tipo renta_maquinaria.
 *
 * Cada línea es: máquina × cantidad (horas o días) × tarifa = subtotal.
 * Ej: RETROEXCAVADORA CAT · 8 horas × L 950.00 = L 7,600.00,
 * llega el 14/jul a las 7:00 AM.
 *
 * SNAPSHOT DE TARIFA: `tarifa_snapshot` se sugiere del catálogo de la
 * máquina al crear (según la unidad) y es ajustable al cotizar. Las
 * actualizaciones posteriores del catálogo NO modifican la línea.
 *
 * SUBTOTAL_CACHE: cantidad × tarifa_snapshot, recalculado en cada
 * guardado con bcmath (mismo patrón que ProyectoRenglon). CHECK en DB
 * valida coherencia con margen 0.02.
 *
 * EXTENSIONES: `es_extension` marca líneas agregadas después de
 * aprobar ("el cliente quiere más horas"). Las líneas originales de
 * la cotización nunca se editan una vez aprobado el proyecto.
 *
 * @property int $id
 * @property int $proyecto_id
 * @property int $maquina_id
 * @property int $orden
 * @property UnidadRenta $unidad
 * @property string $cantidad
 * @property string $tarifa_snapshot
 * @property string $subtotal_cache
 * @property Carbon $fecha_llegada
 * @property string|null $hora_llegada
 * @property bool $es_extension
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proyecto $proyecto
 * @property-read Maquina $maquina
 */
class ProyectoLineaRenta extends Model
{
    /** @use HasFactory<ProyectoLineaRentaFactory> */
    use HasFactory;

    use HasUppercaseAttributes;

    /**
     * Tabla explícita: la pluralización por defecto de Laravel no
     * coincide con nuestra convención en español.
     */
    protected $table = 'proyecto_lineas_renta';

    /** @var list<string> */
    protected $fillable = [
        'proyecto_id',
        'maquina_id',
        'orden',
        'unidad',
        'cantidad',
        'tarifa_snapshot',
        'subtotal_cache',
        'fecha_llegada',
        'hora_llegada',
        'es_extension',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden'           => 'integer',
            'unidad'          => UnidadRenta::class,
            'cantidad'        => 'decimal:2',
            'tarifa_snapshot' => 'decimal:2',
            'subtotal_cache'  => 'decimal:2',
            'fecha_llegada'   => 'date',
            'es_extension'    => 'boolean',
        ];
    }

    // ─── Lifecycle: subtotal coherente ────────────────────────────

    protected static function booted(): void
    {
        // El subtotal SIEMPRE = cantidad × tarifa_snapshot. Recalcular
        // en cada guardado mantiene la coherencia del CHECK constraint
        // sin importar desde dónde se edite (mismo patrón que
        // ProyectoRenglon).
        static::saving(static function (ProyectoLineaRenta $linea): void {
            $cantidad = (string) ($linea->cantidad ?? '0');
            $tarifa = (string) ($linea->tarifa_snapshot ?? '0');
            $crudo = bcmul($cantidad, $tarifa, 4);

            $linea->subtotal_cache = bccomp($crudo, '0', 4) >= 0
                ? bcadd($crudo, '0.005', 2)
                : bcsub($crudo, '0.005', 2);
        });
    }

    // ─── Mutators uppercase ────────────────────────────────────────

    protected function notas(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => self::aMayusculas($value),
        );
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

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Horas pactadas equivalentes de esta línea (días → horas según
     * la jornada de la máquina). Base para comparar contra las horas
     * reales del parte al finalizar.
     */
    public function horasPactadas(): string
    {
        $this->loadMissing('maquina');

        return $this->unidad->horasEquivalentes((string) $this->cantidad, $this->maquina);
    }

    /**
     * Hora de llegada corta "07:00" para etiquetas (o null).
     */
    public function horaLlegadaCorta(): ?string
    {
        return $this->hora_llegada !== null
            ? substr((string) $this->hora_llegada, 0, 5)
            : null;
    }

    /**
     * Etiqueta legible: "8 Horas × L 950.00 · llega 14/jul 07:00".
     */
    public function getEtiquetaAttribute(): string
    {
        $partes = [
            rtrim(rtrim((string) $this->cantidad, '0'), '.').' '.$this->unidad->getLabel(),
            'L '.number_format((float) $this->tarifa_snapshot, 2),
        ];

        $llegada = 'llega '.$this->fecha_llegada->translatedFormat('d/M');

        if ($this->hora_llegada !== null) {
            $llegada .= ' '.$this->horaLlegadaCorta();
        }

        return implode(' × ', $partes).' · '.$llegada;
    }
}
