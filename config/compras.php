<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Ventana de corrección de conteos (horas)
    |--------------------------------------------------------------------------
    | Tiempo desde el ÚLTIMO conteo (verificación o corrección) de una compra
    | CUADRADA (facturado = recibido en todas las líneas) durante el cual aún
    | se permite "Corregir conteo". Vencida la ventana, la compra queda lista
    | para COMPLETAR (cierre definitivo). Con diferencias la ventana no
    | aplica: el reclamo se resuelve recontando o anulando.
    */

    'ventana_correccion_horas' => env('COMPRAS_VENTANA_CORRECCION_HORAS', 24),

];
