<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Browsershot (Chromium headless)
|--------------------------------------------------------------------------
|
| Rutas al binario de Chromium y a Node para generar PDFs con Browsershot.
| En el KVM4 de Hostinger se instala chromium-browser y node; en local
| (Herd/macOS) Browsershot detecta el Chrome del sistema si estas rutas
| quedan null. `no_sandbox` es requerido en VPS sin namespaces de usuario.
|
*/

return [
    'chrome_path' => env('CHROMIUM_PATH'),
    'node_binary' => env('NODE_PATH'),
    'npm_binary'  => env('NPM_PATH'),
    'no_sandbox'  => (bool) env('BROWSERSHOT_NO_SANDBOX', false),
];
