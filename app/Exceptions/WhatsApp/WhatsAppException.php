<?php

declare(strict_types=1);

namespace App\Exceptions\WhatsApp;

use Exception;

/**
 * Fallos del envío directo por WhatsApp (Evolution API) — factory
 * methods por caso (patrón de la casa) con mensajes accionables para
 * la notificación del panel.
 */
class WhatsAppException extends Exception
{
    public static function noConfigurado(): self
    {
        return new self(
            'El envío directo por WhatsApp está apagado o sin configurar. '
            .'Revisa WHATSAPP_ENABLED, WHATSAPP_EVOLUTION_URL y WHATSAPP_EVOLUTION_API_KEY en el .env.'
        );
    }

    public static function sinTelefono(string $cliente): self
    {
        return new self("El cliente {$cliente} no tiene teléfono registrado — captúralo en su ficha.");
    }

    public static function falloEnvio(int $status, string $detalle): self
    {
        return new self(
            "Evolution API respondió {$status}: {$detalle}. "
            .'Verifica que la instancia esté conectada (QR escaneado) en el manager de Evolution.'
        );
    }

    public static function sinConexion(string $detalle): self
    {
        return new self(
            "No se pudo contactar Evolution API: {$detalle}. "
            .'¿Está corriendo el contenedor? (docker compose -f docker/evolution/docker-compose.yml up -d)'
        );
    }
}
