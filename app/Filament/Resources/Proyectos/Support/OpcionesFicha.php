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
 *
 * 2026-09-03: acepta \$excluir para no ofrecer fichas ya tomadas — una ficha
 * ya cargada como renglón (o ya elegida en otra fila del modal) desaparece
 * del selector en vez de dejar que el usuario duplique el renglón.
 */
final class OpcionesFicha
{
    /**
     * @param list<int> $excluir IDs de fichas que YA están tomadas y no se
     *                           deben volver a ofrecer (renglones existentes
     *                           del proyecto + filas ya elegidas en el modal).
     *
     * @return array<int, string>
     */
    public static function paraZona(?int $zonaId, array $excluir = []): array
    {
        if ($zonaId === null) {
            return [];
        }

        $excluir = array_values(array_unique(array_filter($excluir, static fn (int $id): bool => $id > 0)));

        return Ficha::query()
            ->where('zona_id', $zonaId)
            ->where('activa', true)
            ->when($excluir !== [], fn ($q) => $q->whereNotIn('id', $excluir))
            ->with('unidadMedida:id,codigo')
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Ficha $f): array => [
                $f->id => sprintf('%s · %s · %s', $f->codigo, $f->nombre, $f->unidadMedida->codigo),
            ])
            ->all();
    }
}
