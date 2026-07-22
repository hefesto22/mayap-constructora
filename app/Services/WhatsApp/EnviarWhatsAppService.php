<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Exceptions\WhatsApp\WhatsAppException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ÚNICA puerta de envío directo por WhatsApp (Evolution API v2) —
 * decisión Mauricio 2026-07-19: la cotización le LLEGA al cliente
 * desde el sistema, sin abrir pestañas ni adjuntar a mano.
 *
 * Evolution emula WhatsApp Web en el servidor: el QR se escanea UNA
 * vez (manager de Evolution) y la sesión queda guardada. Vía no
 * oficial: SOLO el número de la empresa, volumen normal, nada de spam.
 *
 * Todo envío queda en la bitácora (log 'whatsapp').
 */
final class EnviarWhatsAppService
{
    /**
     * ¿El envío directo está encendido y con credenciales?
     */
    public function habilitado(): bool
    {
        return (bool) config('whatsapp.enabled')
            && (string) config('whatsapp.api_key') !== '';
    }

    /**
     * Envía un mensaje de texto al teléfono dado.
     */
    public function enviarTexto(string $telefono, string $texto, ?int $userId = null): void
    {
        $numero = self::normalizarTelefono($telefono);

        if ($numero === null) {
            throw WhatsAppException::sinTelefono($telefono);
        }

        $this->post('/message/sendText/', [
            'number' => $numero,
            'text'   => $texto,
        ]);

        $this->bitacora('texto', $numero, $texto, $userId);
    }

    /**
     * Envía una IMAGEN (la cotización PNG) con su texto al pie.
     */
    public function enviarImagen(string $telefono, string $rutaAbsoluta, string $caption, ?int $userId = null): void
    {
        $numero = self::normalizarTelefono($telefono);

        if ($numero === null) {
            throw WhatsAppException::sinTelefono($telefono);
        }

        $contenido = @file_get_contents($rutaAbsoluta);

        if ($contenido === false) {
            throw new RuntimeException("No se pudo leer la imagen a enviar: {$rutaAbsoluta}");
        }

        $this->post('/message/sendMedia/', [
            'number'    => $numero,
            'mediatype' => 'image',
            'mimetype'  => 'image/png',
            'fileName'  => basename($rutaAbsoluta),
            'media'     => base64_encode($contenido),
            'caption'   => $caption,
        ]);

        $this->bitacora('imagen', $numero, $caption, $userId);
    }

    /**
     * Envía un DOCUMENTO (la cotización en PDF) con su texto al pie
     * (decisión Mauricio 2026-07-22: al cliente le llega el PDF formal,
     * no una imagen).
     */
    public function enviarDocumento(string $telefono, string $rutaAbsoluta, string $caption, ?int $userId = null): void
    {
        $numero = self::normalizarTelefono($telefono);

        if ($numero === null) {
            throw WhatsAppException::sinTelefono($telefono);
        }

        $contenido = @file_get_contents($rutaAbsoluta);

        if ($contenido === false) {
            throw new RuntimeException("No se pudo leer el documento a enviar: {$rutaAbsoluta}");
        }

        $this->post('/message/sendMedia/', [
            'number'    => $numero,
            'mediatype' => 'document',
            'mimetype'  => 'application/pdf',
            'fileName'  => basename($rutaAbsoluta),
            'media'     => base64_encode($contenido),
            'caption'   => $caption,
        ]);

        $this->bitacora('documento', $numero, $caption, $userId);
    }

    /**
     * Teléfono → formato internacional de WhatsApp. Se limpia a
     * dígitos; 8 dígitos = número hondureño → se antepone 504.
     * Null si no hay nada usable.
     */
    public static function normalizarTelefono(?string $telefono): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $telefono) ?? '';

        if ($digitos === '' || strlen($digitos) < 8) {
            return null;
        }

        return strlen($digitos) === 8 ? '504'.$digitos : $digitos;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $rutaBase, array $payload): void
    {
        if (! $this->habilitado()) {
            throw WhatsAppException::noConfigurado();
        }

        $url = rtrim((string) config('whatsapp.base_url'), '/')
            .$rutaBase
            .(string) config('whatsapp.instance');

        try {
            $respuesta = $this->cliente()->post($url, $payload);
        } catch (ConnectionException $e) {
            throw WhatsAppException::sinConexion($e->getMessage());
        }

        if ($respuesta->failed()) {
            throw WhatsAppException::falloEnvio(
                $respuesta->status(),
                mb_substr($respuesta->body(), 0, 300),
            );
        }
    }

    private function cliente(): PendingRequest
    {
        return Http::withHeaders(['apikey' => (string) config('whatsapp.api_key')])
            ->timeout((int) config('whatsapp.timeout', 15))
            ->acceptJson();
    }

    private function bitacora(string $tipo, string $numero, string $resumen, ?int $userId): void
    {
        activity('whatsapp')
            ->causedBy($userId)
            ->withProperties([
                'tipo'    => $tipo,
                'numero'  => $numero,
                'resumen' => mb_substr($resumen, 0, 200),
            ])
            ->event('mensaje_enviado')
            ->log("WhatsApp ({$tipo}) enviado a {$numero}");
    }
}
