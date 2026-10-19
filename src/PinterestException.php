<?php
declare(strict_types=1);

namespace vielhuber\photobutler;

final class PinterestException extends \RuntimeException
{
    /**
     * Retain the upstream status separately from the application's HTTP response.
     */
    public function __construct(string $message, int $code, public readonly int $providerStatus = 0)
    {
        parent::__construct($message, $code);
    }

    /**
     * Keep missing credentials separate from a rejected Pinterest request.
     */
    public static function notConfigured(): self
    {
        return new self(
            'Pinterest ist noch nicht eingerichtet. App-ID und App-Secret oder einen Zugriffstoken konfigurieren.',
            503
        );
    }

    /**
     * Keep private credential storage failures distinct from provider responses.
     */
    public static function storageUnavailable(): self
    {
        return new self('Der Pinterest-Zugang konnte nicht gespeichert werden. Schreibrechte prüfen.', 503);
    }

    /**
     * Reject malformed input without sending a provider request.
     */
    public static function invalidRequest(): self
    {
        return new self('Ungültige Pinterest-Anfrage.', 400);
    }

    /**
     * Preserve the distinction between an empty collection and an incomplete response.
     */
    public static function invalidResponse(): self
    {
        return new self('Pinterest hat keine gültige Antwort geliefert. Bitte erneut versuchen.', 424);
    }

    /**
     * Never silently substitute another board for the configured collection.
     */
    public static function boardUnavailable(): self
    {
        return new self('Die ausgewählte Pinterest-Pinnwand ist mit diesem Token nicht verfügbar.', 404);
    }

    /**
     * Explain provider failures without exposing response bodies or credentials.
     */
    public static function requestFailed(int $status, int $apiCode = 0, string $apiMessage = ''): self
    {
        $message = match (true) {
            $status === 0 => 'Pinterest ist nicht erreichbar. Bitte erneut versuchen.',
            $status === 401 && $apiMessage === 'Two-factor authentication required.'
                => 'Pinterest verlangt Zwei-Faktor-Authentifizierung für die App-Anmeldung.',
            $status === 401 && $apiCode === 3
                => 'Pinterest unterstützt diesen App-Typ nicht (HTTP 401, API-Code 3). App-Freigabe im Pinterest-Entwicklerportal prüfen bzw. Pinterest-Support kontaktieren.',
            $status === 401 => 'Pinterest hat den Zugang abgelehnt (HTTP 401). App-Daten bzw. Zugriffstoken prüfen.',
            $status === 403 && $apiMessage === 'PINNER_DATA_ACCESS_DENIED'
                => 'Pinterest hat den Zugriff auf diese Pins noch nicht freigegeben (PINNER_DATA_ACCESS_DENIED).',
            $status === 403 => 'Pinterest verweigert den Zugriff (HTTP 403). Leserechte und App-Freigabe prüfen.',
            $status === 404 => 'Die Pinterest-Pinnwand oder der Pin ist nicht mehr verfügbar.',
            $status === 429 => 'Das Pinterest-Anfragelimit ist erreicht. Bitte nach dem Zurücksetzen erneut versuchen.',
            default => 'Pinterest-Anfrage fehlgeschlagen (HTTP ' . $status . '). Bitte erneut versuchen.'
        };
        // Cloudflare replaces 502 bodies, so dependency failures use a distinct status.
        return new self($message, $status === 429 ? 429 : 424, providerStatus: $status);
    }
}
