<?php

// Billing Service: armazenamento (DynamoDB), gateway de pagamento e links.

return [
    'dynamodb' => [
        'table' => env('DYNAMODB_TABLE', 'oficina-billing'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        // Só em local/testes (DynamoDB Local); em produção usa o endpoint da AWS.
        'endpoint' => env('DYNAMODB_ENDPOINT'),
    ],

    'payments' => [
        // mercadopago | fake (checkout simulado, para local e demonstração)
        'driver' => env('PAYMENT_DRIVER', 'fake'),
    ],

    'mercadopago' => [
        'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        // Chave secreta do webhook (painel do MP) para validar o x-signature.
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        // Credenciais de teste: usa o sandbox_init_point no link de pagamento.
        'sandbox' => (bool) env('MERCADOPAGO_SANDBOX', true),
        'timeout' => 10,
    ],

    // Token das rotas internas (consulta de orçamentos/cobranças por OS).
    'internal_token' => env('BILLING_INTERNAL_TOKEN'),

    // Validade dos links assinados enviados ao cliente.
    'link_ttl_days' => (int) env('BILLING_LINK_TTL_DAYS', 7),
];
