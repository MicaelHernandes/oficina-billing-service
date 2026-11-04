<?php

namespace Domain\Billing\Infrastructure\Payments;

/**
 * Validação do header x-signature das notificações do Mercado Pago:
 * HMAC-SHA256 (chave secreta do webhook) sobre o manifesto
 * "id:<data.id>;request-id:<x-request-id>;ts:<ts>;".
 */
final class MercadoPagoSignature
{
    public static function isValid(?string $header, ?string $requestId, string $dataId, string $secret, ?int $now = null, int $toleranceSeconds = 600): bool
    {
        if ($header === null || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, null);
            $parts[$key] = $value;
        }

        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;
        if ($ts === null || $v1 === null) {
            return false;
        }

        // ts em milissegundos; rejeita notificações reaproveitadas muito depois.
        $issuedAt = (int) floor(((int) $ts) / 1000);
        if (abs(($now ?? time()) - $issuedAt) > $toleranceSeconds) {
            return false;
        }

        $manifest = 'id:'.strtolower($dataId).';';
        if ($requestId !== null && $requestId !== '') {
            $manifest .= "request-id:{$requestId};";
        }
        $manifest .= "ts:{$ts};";

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $v1);
    }
}
