<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoPago;
use Database\Factories\PlanillaLineaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Línea de planilla — pago de un empleado, cargado a una obra. El monto se
 * deriva del tipo de pago (jornal: días × tarifa, salario: tarifa, destajo:
 * capturado).
 *
 * @property int $id
 * @property int $planilla_id
 * @property int $empleado_id
 * @property int|null $proyecto_id
 * @property TipoPago $tipo_pago
 * @property string|null $dias_trabajados
 * @property string $tarifa_aplicada
 * @property string|null $descripcion
 * @property string $monto_bruto
 * @property string|null $retencion_porcentaje
 * @property string $retencion_monto
 * @property string $deducciones
 * @property string $monto_neto
 * @property string|null $notas
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Planilla $planilla
 * @property-read Empleado $empleado
 * @property-read Proyecto|null $proyecto
 */
class PlanillaLinea extends Model
{
    /** @use HasFactory<PlanillaLineaFactory> */
    use HasFactory;

    protected $table = 'planilla_lineas';

    /** @var list<string> */
    protected $fillable = [
        'planilla_id',
        'empleado_id',
        'proyecto_id',
        'tipo_pago',
        'dias_trabajados',
        'tarifa_aplicada',
        'descripcion',
        'monto_bruto',
        'retencion_porcentaje',
        'retencion_monto',
        'deducciones',
        'monto_neto',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo_pago'            => TipoPago::class,
            'dias_trabajados'      => 'decimal:2',
            'tarifa_aplicada'      => 'decimal:2',
            'monto_bruto'          => 'decimal:2',
            'retencion_porcentaje' => 'decimal:2',
            'retencion_monto'      => 'decimal:2',
            'deducciones'          => 'decimal:2',
            'monto_neto'           => 'decimal:2',
        ];
    }

    // ─── Relaciones ────────────────────────────────────────────────

    /**
     * @return BelongsTo<Planilla, $this>
     */
    public function planilla(): BelongsTo
    {
        return $this->belongsTo(Planilla::class);
    }

    /**
     * @return BelongsTo<Empleado, $this>
     */
    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    /**
     * @return BelongsTo<Proyecto, $this>
     */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }
}
