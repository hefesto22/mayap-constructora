<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| WhatsApp — envío directo vía Evolution API (decisión Mauricio 2026-07-19)
|--------------------------------------------------------------------------
| Evolution API es un WhatsApp Web que vive en el servidor (contenedor
| Docker, ver docker/evolution/). Se escanea el QR UNA vez con el número
| de la empresa y el sistema puede enviar cotizaciones e imágenes
| directo al cliente, sin abrir nada.
|
| APAGADO por defecto: sin WHATSAPP_ENABLED=true los botones de envío
| directo no aparecen y el flujo wa.me sigue siendo el respaldo.
|
| Desarrollo: número personal de prueba. Producción: SOLO el número de
| MAYAP (vía no oficial — un número quemado por spam no se recupera).
*/

return [

    'enabled' => env('WHATSAPP_ENABLED', false),

    // URL del contenedor de Evolution API (sin slash final).
    'base_url' => env('WHATSAPP_EVOLUTION_URL', 'http://localhost:8081'),

    // La AUTHENTICATION_API_KEY configurada en Evolution.
    'api_key' => env('WHATSAPP_EVOLUTION_API_KEY', ''),

    // Nombre de la instancia (la sesión del número escaneado).
    'instance' => env('WHATSAPP_EVOLUTION_INSTANCE', 'mayap'),

    // Segundos de espera por respuesta de Evolution.
    'timeout' => (int) env('WHATSAPP_TIMEOUT', 15),

];
