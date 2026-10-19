<?php

namespace Domain\Billing\Presentation\Controllers;

use App\Http\Controllers\Controller;
use Domain\Billing\Application\PaymentNotificationHandler;
use Domain\Billing\Domain\Ports\GatewayPayment;
use Domain\Billing\Domain\Repositories\BillingStoreInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use OpenApi\Attributes as OA;

/** Checkout simulado (PAYMENT_DRIVER=fake), para local e demonstração. */
class FakeCheckoutController extends Controller
{
    public function __construct(
        private readonly BillingStoreInterface $store,
        private readonly PaymentNotificationHandler $handler,
    ) {}

    #[OA\Get(
        path: '/billing/api/public/payments/{payment}/fake-checkout',
        summary: 'Checkout simulado: dados da cobrança e URL para aprovar/recusar (só com PAYMENT_DRIVER=fake)',
        tags: ['Checkout simulado'],
        parameters: [new OA\Parameter(name: 'payment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Cobrança e URL assinada de pagamento'),
            new OA\Response(response: 403, description: 'Assinatura inválida ou expirada'),
            new OA\Response(response: 404, description: 'Cobrança não encontrada ou driver não é fake'),
        ]
    )]
    public function show(string $payment): JsonResponse
    {
        $found = $this->store->findPayment($payment);
        if ($found === null) {
            return response()->json(['message' => 'Cobrança não encontrada.'], 404);
        }

        return response()->json([
            'data' => $found->toArray(),
            'pay_url' => URL::temporarySignedRoute('billing.fake-checkout.pay', now()->addHour(), ['payment' => $payment]),
            'hint' => 'POST pay_url com {"status": "approved"} ou {"status": "rejected"}',
        ]);
    }

    #[OA\Post(
        path: '/billing/api/public/payments/{payment}/fake-checkout/pay',
        summary: 'Simula o resultado do pagamento como se fosse o webhook do Mercado Pago',
        tags: ['Checkout simulado'],
        parameters: [new OA\Parameter(name: 'payment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['approved', 'rejected']),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Cobrança atualizada; evento publicado para a saga'),
            new OA\Response(response: 404, description: 'Cobrança não encontrada'),
            new OA\Response(response: 422, description: 'Status inválido'),
        ]
    )]
    public function pay(Request $request, string $payment): JsonResponse
    {
        $status = $request->validate(['status' => ['required', 'in:approved,rejected']])['status'];

        $updated = $this->handler->apply(new GatewayPayment(
            id: 'fake-'.substr(md5($payment), 0, 12),
            status: $status,
            externalReference: $payment,
            statusDetail: $status === 'rejected' ? 'cc_rejected_other_reason' : 'accredited',
        ));

        return $updated
            ? response()->json(['data' => $updated->toArray()])
            : response()->json(['message' => 'Cobrança não encontrada.'], 404);
    }
}
