<?php

namespace App\Services\Mail;

/**
 * User-facing mail error. The message is shown in the UI as-is (German),
 * so never put credentials or raw server dumps into it.
 */
class MailException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502, public readonly bool $reconnect = false)
    {
        parent::__construct($message);
    }

    public static function fromThrowable(\Throwable $e, ?string $hint = null): self
    {
        if ($e instanceof self) return $e;
        $class = class_basename($e);
        $msg = strtolower($e->getMessage());
        if ($class === 'AuthFailedException' || str_contains($msg, 'authenticat') || str_contains($msg, 'login failed') || str_contains($msg, '535')) {
            return new self(trim('Anmeldung fehlgeschlagen — E-Mail-Adresse oder Passwort ist falsch. ' . ($hint ?? '')), 422);
        }
        if ($class === 'ConnectionFailedException' || str_contains($msg, 'connection') || str_contains($msg, 'timed out') || str_contains($msg, 'getaddrinfo')) {
            return new self('Der Mailserver ist nicht erreichbar. Bitte später erneut versuchen.', 504);
        }
        return new self('Der Mailserver hat die Anfrage abgelehnt.', 502);
    }
}
