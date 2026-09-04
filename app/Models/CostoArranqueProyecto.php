<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RubroCostoArranque;
use Database\Factories\CostoArranqueProyectoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una partida del gasto que la obra ya arrastraba ANTES de entrar al sistema.
 *
 * Existe porque el arrastre de una obra heredada no se recuerda de una sola
 * sentada: van apareciendo facturas viejas de a poco. Cada partida deja
 * asentado de qué era, de cuándo y quién la cargó, y la suma por rubro se
 * cachea en el proyecto (costo_arranque_*) para que CostoProyectoService no
 * tenga que agregar en cada render de tabla.
 *
 * @property int $id
 * @property int $proyecto_id
 * @property RubroCostoArranque $rubro
 * @property numeric-string $monto
 * @property Carbon $fecha
 * @property string $descripcion
 * @property int|null $registrado_por_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proyecto $proyecto
 * @property-read User|null $registradoPor
 */
class CostoArranqueProyecto extends Model
{
    /** @use HasFactory<CostoArranqueProyectoFactory> */
    use HasFactory;

    protected $table = 'costos_arranque_proyecto';

    /** @var list<string> */
    protected $fillable = [
        'proyecto_id',
        'rubro',
        'monto',
        'fecha',
        'descripcion',
        'registrado_por_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rubro' => RubroCostoArranque::class,
            'monto' => 'decimal:2',
            'fecha' => 'date',
        ];
    }

    /** @return BelongsTo<Proyecto, $this> */
    public function proyecto(): BelongsTo
    {
        return $this->belongsTo(Proyecto::class);
    }

    /** @return BelongsTo<User, $this> */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por_id');
    }
}
