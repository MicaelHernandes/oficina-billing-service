<?php

use Domain\Billing\Presentation\Controllers\FakeCheckoutController;
use Domain\Billing\Presentation\Controllers\InternalBillingController;
use Domain\Billing\Presentation\Controllers\MercadoPagoWebhookController;
use Domain\Billing\Presentation\Controllers\PublicBudgetController;
use Illuminate\Support\Facades\Route;

/*
| Rotas do Billing (prefixo /billing/api). Os comandos da saga chegam pela
| fila SQS, não por HTTP.
*/

// ── Cliente: links assinados enviados por e-mail ─────────────────────────
Route::middleware('signed')->prefix('public')->group(function () {
    Route::get('budgets/{budget}', [PublicBudgetController::class, 'show'])->name('billing.public.budgets.show');
    Route::get('budgets/{budget}/approve', [PublicBudgetController::class, 'approve'])->name('billing.public.budgets.approve');
    Route::get('budgets/{budget}/reject', [PublicBudgetController::class, 'reject'])->name('billing.public.budgets.reject');

    // Checkout simulado: só existe com PAYMENT_DRIVER=fake.
    if (config('billing.payments.driver') === 'fake') {
        Route::get('payments/{payment}/fake-checkout', [FakeCheckoutController::class, 'show'])->name('billing.fake-checkout');
        Route::post('payments/{payment}/fake-checkout/pay', [FakeCheckoutController::class, 'pay'])->name('billing.fake-checkout.pay');
    }
});

// ── Mercado Pago ─────────────────────────────────────────────────────────
Route::post('webhooks/mercadopago', MercadoPagoWebhookController::class)->name('billing.webhooks.mercadopago');

// ── Interno ──────────────────────────────────────────────────────────────
Route::middleware('internal')->group(function () {
    Route::get('order-services/{osId}', [InternalBillingController::class, 'show'])->whereNumber('osId');
});
