<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Oficina — Billing Service',
    version: '1.0.0',
    description: 'Orçamento e pagamento (Mercado Pago) da OS. Os comandos da saga chegam pela fila SQS; estas rotas atendem o cliente (links assinados), o webhook do Mercado Pago e consultas internas.'
)]
#[OA\Server(
    url: L5_SWAGGER_CONST_HOST,
    description: 'Servidor principal'
)]
#[OA\SecurityScheme(
    securityScheme: 'internalToken',
    type: 'http',
    scheme: 'bearer',
    description: 'BILLING_INTERNAL_TOKEN'
)]
final class OpenApiSpec {}
