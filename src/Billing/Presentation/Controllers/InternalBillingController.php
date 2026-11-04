<?php

namespace Domain\Billing\Presentation\Controllers;

use App\Http\Controllers\Controller;
use Domain\Billing\Domain\Entities\Budget;
use Domain\Billing\Domain\Enums\BudgetStatus;
use Domain\Billing\Domain\Repositories\BillingStoreInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
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
            new OA\Response(response: 200, description: 'Revisões do orçamento e cobranças, mais recentes primeiro. O orçamento pendente traz os links assinados de aprovação/recusa (os mesmos do e-mail ao cliente), para o atendente reenviar.'),
            new OA\Response(response: 401, description: 'Token ausente ou inválido'),
        ]
    )]
    public function show(int $osId): JsonResponse
    {
        return response()->json(['data' => [
            'os_id' => $osId,
            'budgets' => array_map(fn (Budget $b) => $b->toArray() + $this->links($b), $this->store->budgetsOf($osId)),
            'payments' => array_map(fn ($p) => $p->toArray(), $this->store->paymentsOf($osId)),
        ]]);
    }

    /** @return array<string, array<string, string>> */
    private function links(Budget $budget): array
    {
        if ($budget->getStatus() !== BudgetStatus::Pending) {
            return [];
        }

        $expires = now()->addDays((int) config('billing.link_ttl_days'));
        $sign = fn (string $route, array $extra = []) => URL::temporarySignedRoute($route, $expires, ['budget' => $budget->getId()] + $extra);

        return ['links' => [
            'approve' => $sign('billing.public.budgets.approve'),
            'reject' => $sign('billing.public.budgets.reject'),
            'cancel' => $sign('billing.public.budgets.reject', ['final' => 1]),
        ]];
    }
}
