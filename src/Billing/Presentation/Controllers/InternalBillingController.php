<?php

namespace Domain\Billing\Presentation\Controllers;

use App\Http\Controllers\Controller;
use Domain\Billing\Domain\Repositories\BillingStoreInterface;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class InternalBillingController extends Controller
{
    public function __construct(private readonly BillingStoreInterface $store) {}

    #[OA\Get(
        path: '/billing/api/order-services/{osId}',
        summary: 'Orçamentos e cobranças de uma OS (rota interna, Bearer BILLING_INTERNAL_TOKEN)',
        security: [['internalToken' => []]],
        tags: ['Interno'],
        parameters: [new OA\Parameter(name: 'osId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Revisões do orçamento e cobranças, mais recentes primeiro'),
            new OA\Response(response: 401, description: 'Token ausente ou inválido'),
        ]
    )]
    public function show(int $osId): JsonResponse
    {
        return response()->json(['data' => [
            'os_id' => $osId,
            'budgets' => array_map(fn ($b) => $b->toArray(), $this->store->budgetsOf($osId)),
            'payments' => array_map(fn ($p) => $p->toArray(), $this->store->paymentsOf($osId)),
        ]]);
    }
}
