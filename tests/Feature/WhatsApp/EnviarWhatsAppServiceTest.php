<?php

declare(strict_types=1);

use App\Exceptions\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\EnviarWhatsAppService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Golden tests del envío directo por WhatsApp (Evolution API): payloads
| correctos, teléfono normalizado, apagado seguro y errores accionables.
| Todo con Http::fake — jamás se toca la API real en la suite.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    config([
        'whatsapp.enabled'  => true,
        'whatsapp.base_url' => 'http://evolution.test',
        'whatsapp.api_key'  => 'llave-secreta',
        'whatsapp.instance' => 'mayap',
    ]);

    $this->service = app(EnviarWhatsAppService::class);
});

test('normaliza teléfonos hondureños y descarta basura', function (): void {
    expect(EnviarWhatsAppService::normalizarTelefono('9999-8888'))->toBe('50499998888')
        ->and(EnviarWhatsAppService::normalizarTelefono('+504 9999 8888'))->toBe('50499998888')
        ->and(EnviarWhatsAppService::normalizarTelefono('50499998888'))->toBe('50499998888')
        ->and(EnviarWhatsAppService::normalizarTelefono('123'))->toBeNull()
        ->and(EnviarWhatsAppService::normalizarTelefono(null))->toBeNull();
});

test('enviar texto pega al endpoint correcto con la llave y el número normalizado', function (): void {
    Http::fake(['evolution.test/*' => Http::response(['status' => 'PENDING'], 201)]);

    $this->service->enviarTexto('9999-8888', 'Hola desde MAYAP');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://evolution.test/message/sendText/mayap'
            && $request->hasHeader('apikey', 'llave-secreta')
            && $request['number'] === '50499998888'
            && $request['text'] === 'Hola desde MAYAP';
    });

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'whatsapp',
        'event'    => 'mensaje_enviado',
    ]);
});

test('enviar imagen manda el PNG en base64 con su caption', function (): void {
    Http::fake(['evolution.test/*' => Http::response([], 201)]);

    $ruta = sys_get_temp_dir().'/cotizacion-test.png';
    file_put_contents($ruta, 'PNG-FALSO');

    $this->service->enviarImagen('9999-8888', $ruta, 'Cotización PROY-2026-00006');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://evolution.test/message/sendMedia/mayap'
            && $request['mediatype'] === 'image'
            && $request['media'] === base64_encode('PNG-FALSO')
            && $request['caption'] === 'Cotización PROY-2026-00006'
            && $request['fileName'] === 'cotizacion-test.png';
    });
});

test('apagado o sin llave no intenta enviar nada', function (): void {
    config(['whatsapp.enabled' => false]);
    Http::fake();

    expect(fn () => $this->service->enviarTexto('9999-8888', 'Hola'))
        ->toThrow(WhatsAppException::class, 'apagado');

    Http::assertNothingSent();

    expect($this->service->habilitado())->toBeFalse();
});

test('una respuesta de error de Evolution se traduce a mensaje accionable', function (): void {
    Http::fake(['evolution.test/*' => Http::response(['error' => 'instance not connected'], 400)]);

    expect(fn () => $this->service->enviarTexto('9999-8888', 'Hola'))
        ->toThrow(WhatsAppException::class, '400');
});

test('un teléfono inservible se rechaza antes de tocar la API', function (): void {
    Http::fake();

    expect(fn () => $this->service->enviarTexto('n/a', 'Hola'))
        ->toThrow(WhatsAppException::class, 'teléfono');

    Http::assertNothingSent();
});

test('enviar documento manda el PDF en base64 con su caption', function (): void {
    Http::fake(['evolution.test/*' => Http::response([], 201)]);

    $ruta = sys_get_temp_dir().'/cotizacion-test.pdf';
    file_put_contents($ruta, 'PDF-FALSO');

    $this->service->enviarDocumento('9999-8888', $ruta, 'Cotización PROY-2026-00006');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'http://evolution.test/message/sendMedia/mayap'
            && $request['mediatype'] === 'document'
            && $request['mimetype'] === 'application/pdf'
            && $request['media'] === base64_encode('PDF-FALSO')
            && $request['fileName'] === 'cotizacion-test.pdf';
    });
});
