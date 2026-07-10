<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Presentación de cantidades en PANTALLA — la base de datos siempre guarda
 * NUMERIC(12,4); esto es solo cómo lo lee el usuario:
 *
 *  - corta():    2 decimales fijos, para campos de SOLO LECTURA
 *                (facturado, conteo actual, solicitado).
 *  - sinCeros(): quita ceros de cola SIN perder precisión, para prellenar
 *                campos EDITABLES ("12.0000" → "12"; "12.3456" queda igual
 *                — jamás se redondea algo que el usuario va a guardar).
 */
final class Cantidad
{
    public static function corta(string|int|float|null $valor): string
    {
        return number_format((float) ($valor ?? 0), 2);
    }

    public static function sinCeros(string|int|float|null $valor): string
    {
        $texto = (string) ($valor ?? '0');

        return str_contains($texto, '.')
            ? (rtrim(rtrim($texto, '0'), '.') ?: '0')
            : $texto;
    }
}
