<?php

// Endpoint /metrics (promphp/prometheus_client_php). Em produção usa APCu,
// compartilhado entre os workers php-fpm do pod; em testes, memória.

return [
    'storage' => env('METRICS_STORAGE', 'apcu'), // apcu | memory
    'namespace' => 'oficina_billing',
];
