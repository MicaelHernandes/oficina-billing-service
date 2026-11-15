<?php

use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

// Métricas para o Prometheus (scrape via ServiceMonitor). Exposto apenas
// dentro do cluster (Service ClusterIP), não pelo Ingress.
Route::get('/metrics', MetricsController::class);
