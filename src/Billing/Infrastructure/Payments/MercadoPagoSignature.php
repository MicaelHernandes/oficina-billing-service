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
        return self::failureReason($header, $requestId, $dataId, $secret, $now, $toleranceSeconds) === null;
    }

    /**
     * Motivo da recusa (missing, expired, mismatch) ou null se a assinatura for válida.
     * Serve para o log do webhook dizer por que a notificação foi recusada.
     */
    public static function failureReason(?string $header, ?string $requestId, string $dataId, string $secret, ?int $now = null, int $toleranceSeconds = 600): ?string
    {
        if ($header === null || $header === '') {
            return 'missing';
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, null);
            $parts[$key] = $value;
        }

        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;
        if ($ts === null || $v1 === null) {
            return 'missing';
        }

        // O Mercado Pago envia ts em segundos (ex.: 1704908010); aceita também
        // milissegundos. Rejeita notificações reaproveitadas muito depois.
        $issuedAt = (int) $ts > 99_999_999_999 ? intdiv((int) $ts, 1000) : (int) $ts;
        if (abs(($now ?? time()) - $issuedAt) > $toleranceSeconds) {
            return 'expired';
        }

        $manifest = 'id:'.strtolower($dataId).';';
        if ($requestId !== null && $requestId !== '') {
            $manifest .= "request-id:{$requestId};";
        }
        $manifest .= "ts:{$ts};";

        return hash_equals(hash_hmac('sha256', $manifest, $secret), $v1) ? null : 'mismatch';
    }
}
