<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUppercaseAttributes;
use App\Services\Proyectos\CalcularAvanceProyectoService;
use Database\Factories\ProyectoActividadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Actividad / hito de avance físico de un proyecto.
 *
 * Se marca como completada y aporta al porcentaje de avance de la
 * obra según su `peso` (ponderación opcional). Ver el modelo Proyecto
 * y CalcularAvanceProyectoService para el cómputo del avance.
 *
 * @property int $id
 * @property int $proyecto_id
 * @property int $orden
 * @property string $nombre
 * @property string|null $peso
 * @property bool $completada
 * @property Carbon|null $fecha_completada
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proyecto $proyecto
 */
class ProyectoActividad extends Model
{
    /** @use HasFactory<ProyectoActividadFactory> */
    use HasFactory;

    use HasUppercaseAttributes;

    protected $table = 'proyecto_actividades';

    /** @var list<string> */
    protected $fillable = [
        'proyecto_id',
        'orden',
        'nombre',
        'peso',
        'completada',
        'fecha_completada',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden'            => 'integer',
            'peso'             => 'decimal:2',
            'completada'       => 'boolean',
            'fecha_completada' => 'date',
        ];
    }

    // ─── Lifecycle: mantener el avance del proyecto sincronizado ───

    protected static function booted(): void
    {
        // Coherencia completada ↔ fecha_completada (defiende el CHECK
        // constraint sin importar desde dónde se guarde):
        //  - se marca completada sin fecha → fecha = hoy.
        //  - se desmarca → fecha = null.
        static::saving(static function (ProyectoActividad $actividad): void {
            if ($actividad->completada && $actividad->fecha_completada === null) {
                $actividad->fecha_completada = Carbon::today();
            }

            if (! $actividad->completada) {
                $actividad->fecha_completada = null;
            }
        });

        // Cualquier cambio en las actividades recalcula el % de avance
        // del proyecto padre, para que el cache nunca quede stale sin
        // importar desde dónde se haya editado.
        static::saved(static fn (ProyectoActividad $actividad): null => $actividad->recalcularAvanceProyecto());
        static::deleted(static fn (ProyectoActividad $actividad): null => $actividad->recalcularAvanceProyecto());
    }

    private function recalcularAvanceProyecto(): null
    {
        $proyecto = $this->proyecto()->first();

        if ($proyecto instanceof Proyecto) {
            app(CalcularAvanceProyectoService::class)->recalcular($proyecto);
        }

        return null;
    }

    // ─── Mutators uppercase ────────────────────────────────────────

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
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    // ─── Scopes ────────────────────────────────────────────────────

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeCompletadas(Builder $query): Builder
    {
        return $query->where('completada', true);
    }
}
