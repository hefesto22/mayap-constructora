<?php

declare(strict_types=1);

namespace App\Filament\Resources\Proyectos\Support;

use App\Models\Ficha;

/**
 * Opciones de fichas APU para los selectores de renglones, con etiqueta
 * COMPACTA ("código · nombre · unidad") para que el listado se lea limpio
 * aunque haya 30+ fichas. El precio NO va en la etiqueta porque vive en
 * su propia columna.
 *
 * Reutilizado por el form de Composición y por la carga masiva, para no
 * duplicar la consulta ni el formato.
 */
final class OpcionesFicha
{
    /**
     * @return array<int, string>
     */
    public static function paraZona(?int $zonaId): array
    {
        if ($zonaId === null) {
            return [];
        }

        return Ficha::query()
            ->where('zona_id', $zonaId)
            ->where('activa', true)
            ->with('unidadMedida:id,codigo')
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Ficha $f): array => [
                $f->id => sprintf('%s · %s · %s', $f->codigo, $f->nombre, $f->unidadMedida->codigo),
            ])
            ->all();
    }
}
