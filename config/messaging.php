<?php

// Mensageria entre microsserviços (Fase 4).
//
// O Billing consome os comandos `billing.*` do orquestrador (OS Service) na
// sua fila SQS e publica os eventos no seu tópico SNS (via outbox).
// Em local/testes o driver `log` só registra as mensagens publicadas.

return [
    // Identifica o remetente no envelope das mensagens.
    'source' => env('MESSAGING_SOURCE', 'oficina-billing-service'),

    // sns | log
    'publisher' => env('MESSAGING_PUBLISHER', 'log'),

    // sqs | null
    'consumer' => env('MESSAGING_CONSUMER', 'null'),

    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

    'sns' => [
        'topic_arn' => env('MESSAGING_TOPIC_ARN'),
    ],

    'sqs' => [
        'queue_url' => env('MESSAGING_QUEUE_URL'),
        'wait_seconds' => (int) env('MESSAGING_WAIT_SECONDS', 20),
        'max_messages' => 10,
    ],
];
