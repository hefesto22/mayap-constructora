<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoReporteFiscal;
use Database\Factories\ReporteFiscalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Reporte mensual de control — el PDF permanente de cada mes. Dos tipos
 * conviven en la misma tabla (unique por tipo + período):
 *
 * - `facturas`: todas las compras del mes con las fotos de sus facturas.
 * - `pagos`:    todos los abonos a proveedores del mes con las fotos de
 *               sus comprobantes, y las compras saldadas ese mes.
 *
 * Nacen del comando diario `compras:ciclo-fiscal-mensual` (o del botón
 * "Generar" en la pantalla de Reportes fiscales).
 *
 * El PDF vive en el disco `local` (storage/app, PRIVADO — no es público
 * como las fotos temporales) y se descarga desde el panel con permiso
 * de compras. `fotos_incluidas` son las rutas archivadas dentro del
 * PDF: la única lista que la purga tiene permitido borrar.
 *
 * `compras_count` cuenta los registros archivados: compras en el tipo
 * facturas, abonos en el tipo pagos (columna histórica reutilizada).
 *
 * @property int $id
 * @property TipoReporteFiscal $tipo
 * @property Carbon $periodo
 * @property string $path
 * @property int $compras_count
 * @property int $fotos_count
 * @property array<int, string>|null $fotos_incluidas
 * @property Carbon|null $fotos_purgadas_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReporteFiscal extends Model
{
    /** @use HasFactory<ReporteFiscalFactory> */
    use HasFactory;

    /** Días de colchón entre generar el PDF y purgar las fotos. */
    public const int DIAS_COLCHON = 7;

    protected $table = 'reportes_fiscales';

    /** @var list<string> */
    protected $fillable = [
        'tipo',
        'periodo',
        'path',
        'compras_count',
        'fotos_count',
        'fotos_incluidas',
        'fotos_purgadas_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo'              => TipoReporteFiscal::class,
            'periodo'           => 'date',
            'fotos_incluidas'   => 'array',
            'fotos_purgadas_at' => 'datetime',
        ];
    }

    /**
     * "Julio 2026" — cómo se le llama al período en pantalla y avisos.
     */
    public function periodoLabel(): string
    {
        return ucfirst($this->periodo->translatedFormat('F Y'));
    }

    public function rutaAbsoluta(): string
    {
        return Storage::disk('local')->path($this->path);
    }

    /**
     * ¿El PDF existe y no está vacío? La purga JAMÁS corre sobre un
     * reporte con PDF dañado — primero se regenera.
     */
    public function pdfSano(): bool
    {
        $ruta = $this->rutaAbsoluta();

        return is_file($ruta) && filesize($ruta) > 0;
    }

    /**
     * Fecha en la que la purga liberará (o liberó) las fotos del mes.
     */
    public function fechaPurga(): Carbon
    {
        return ($this->created_at ?? now())->copy()->addDays(self::DIAS_COLCHON);
    }
}
