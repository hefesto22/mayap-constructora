<?php

declare(strict_types=1);

namespace App\Services\Fichas;

use App\Enums\CategoriaItem;

/**
 * Resultado del cálculo de una ficha APU — Value Object inmutable.
 *
 * Todos los montos públicos están redondeados a 2 decimales (centavos
 * HNL). Los cálculos internos del Service se hacen sin redondear y
 * solo se redondea al armar este DTO — esto evita el "drift" de
 * céntimos por redondeo en cascada que se ve en hojas de cálculo
 * mal implementadas.
 *
 * `subtotalesPorCategoria` mapea cada categoría visual del reporte
 * (Materiales / Mano de Obra / Herramienta y Equipo / Indirectos) a
 * la suma redondeada de sus líneas — incluyendo líneas tipo
 * porcentaje cuya `categoria_destino` cae en esa sección.
 *
 * Distinción importante:
 *  - `subtotal`     = MAT + MO + HE + IND. Es el SUB TOTAL del Excel
 *                     del oficio. Sobre este aplica la utilidad.
 *  - `costoDirecto` = MAT + MO + HE solamente. NO se usa para utilidad;
 *                     se expone porque sirve como base de cálculo de
 *                     líneas tipo porcentaje (ej: "Imprevistos 5% sobre
 *                     costo directo").
 */
final readonly class ResultadoCalculoFicha
{
    /**
     * @param array<value-of<CategoriaItem>, string> $subtotalesPorCategoria
     * @param list<DetalleLineaCalculada> $detallesPorLinea
     */
    public function __construct(
        public array $subtotalesPorCategoria,
        public array $detallesPorLinea,
        public string $costoDirecto,
        public string $subtotal,
        public string $utilidadPorcentaje,
        public string $utilidadMonto,
        public string $precioVenta,
    ) {}

    /**
     * Subtotal de una categoría visual del reporte (default '0.00' si no hay líneas).
     */
    public function subtotalDe(CategoriaItem $categoria): string
    {
        return $this->subtotalesPorCategoria[$categoria->value] ?? '0.00';
    }
}
