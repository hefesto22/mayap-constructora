<?php

declare(strict_types=1);

namespace App\Services\Reportes;

/**
 * Contrato HTML → PDF. La implementación real es PdfRenderer
 * (Browsershot/Chromium); los tests atan una implementación falsa que
 * escribe un archivo dummy — generar un PDF real requiere Chrome y no
 * pertenece a la suite.
 *
 * Enlazado en AppServiceProvider::register().
 */
interface RenderizadorPdf
{
    /**
     * Convierte HTML en PDF y lo guarda en la ruta dada (la crea si
     * hace falta). Devuelve la ruta absoluta del archivo generado.
     */
    public function guardar(string $html, string $rutaDestino): string;
}
