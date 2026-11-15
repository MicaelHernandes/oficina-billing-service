<?php

use Domain\Billing\Domain\Ports\GatewayPayment;
use Domain\Billing\Infrastructure\Payments\MercadoPagoSignature;

describe('MercadoPagoSignature', function () {
    $sign = fn (string $manifest, string $secret = 'k') => hash_hmac('sha256', $manifest, $secret);
    $now = 1_760_000_000;
    $ts = $now * 1000;

    it('aceita a assinatura correta (com e sem request-id)', function () use ($sign, $now, $ts) {
        $v1 = $sign("id:abc123;request-id:rq;ts:{$ts};");
        expect(MercadoPagoSignature::isValid("ts={$ts},v1={$v1}", 'rq', 'ABC123', 'k', $now))->toBeTrue();

        $v1 = $sign("id:abc123;ts:{$ts};");
        expect(MercadoPagoSignature::isValid("ts={$ts}, v1={$v1}", null, 'abc123', 'k', $now))->toBeTrue();
    });

    it('aceita ts em segundos, como o Mercado Pago envia', function () use ($sign, $now) {
        $v1 = $sign("id:189825429878;request-id:rq;ts:{$now};");
        expect(MercadoPagoSignature::isValid("ts={$now},v1={$v1}", 'rq', '189825429878', 'k', $now + 5))->toBeTrue();
    });

    it('informa o motivo da recusa', function () use ($sign, $now, $ts) {
        $v1 = $sign("id:1;ts:{$ts};");

        expect(MercadoPagoSignature::failureReason(null, null, '1', 'k', $now))->toBe('missing')
            ->and(MercadoPagoSignature::failureReason("ts={$ts},v1={$v1}", null, '1', 'k', $now + 3600))->toBe('expired')
            ->and(MercadoPagoSignature::failureReason("ts={$ts},v1={$v1}", null, '1', 'outra', $now))->toBe('mismatch')
            ->and(MercadoPagoSignature::failureReason("ts={$ts},v1={$v1}", null, '1', 'k', $now))->toBeNull();
    });

    it('recusa assinatura errada, ausente, incompleta ou antiga', function () use ($sign, $now, $ts) {
        $v1 = $sign("id:1;request-id:rq;ts:{$ts};");

        expect(MercadoPagoSignature::isValid("ts={$ts},v1={$v1}", 'rq', '1', 'outra', $now))->toBeFalse()
            ->and(MercadoPagoSignature::isValid(null, 'rq', '1', 'k', $now))->toBeFalse()
            ->and(MercadoPagoSignature::isValid("ts={$ts}", 'rq', '1', 'k', $now))->toBeFalse()
            ->and(MercadoPagoSignature::isValid("ts={$ts},v1={$v1}", 'rq', '1', 'k', $now + 3600))->toBeFalse();
    });
});

it('normaliza os status do Mercado Pago', function (string $mp, string $expected) {
    expect(GatewayPayment::normalize($mp))->toBe($expected);
})->with([
    ['approved', 'approved'],
    ['authorized', 'approved'],
    ['rejected', 'rejected'],
    ['cancelled', 'rejected'],
    ['charged_back', 'rejected'],
    ['pending', 'pending'],
    ['in_process', 'pending'],
]);
