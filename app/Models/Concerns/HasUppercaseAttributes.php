<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Trait reutilizable para modelos que normalizan campos de texto a
 * mayúsculas al persistir.
 *
 * Convención del dominio constructor / facturación HN: nombres,
 * códigos, descripciones, observaciones — todo va en mayúsculas para
 * consistencia visual y evitar variaciones ortográficas
 * ("cemento" vs "Cemento" vs "CEMENTO" representando lo mismo).
 *
 * NO aplicar a: símbolos físicos (m² ≠ M²), correos, contraseñas,
 * nombres de personas (User.name).
 *
 * Uso típico desde un modelo:
 *   use HasUppercaseAttributes;
 *
 *   protected function nombre(): Attribute
 *   {
 *       return Attribute::make(
 *           set: static fn (?string $v): ?string => self::aMayusculas($v),
 *       );
 *   }
 */
trait HasUppercaseAttributes
{
    /**
     * Convierte un string a mayúsculas con soporte UTF-8 (acentos, ñ).
     *
     * - Trim de espacios al inicio/fin antes de transformar.
     * - Strings vacíos o que contienen solo espacios → null
     *   (mantiene la integridad de campos nullable).
     * - null → null (idempotente).
     */
    protected static function aMayusculas(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_strtoupper(trim($value), 'UTF-8');
    }
}
