<?php

namespace Domain\Billing\Presentation\Controllers;

use App\Http\Controllers\Controller;
use Domain\Billing\Application\BudgetDecision;
use Domain\Billing\Domain\Exceptions\DomainException;
use Domain\Billing\Domain\Exceptions\NotFoundException;
use Domain\Billing\Domain\Repositories\BillingStoreInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Orçamento (cliente)', description: 'Links assinados enviados por e-mail ao cliente')]
class PublicBudgetController extends Controller
{
    public function __construct(
        private readonly BillingStoreInterface $store,
        private readonly BudgetDecision $decision,
    ) {}

    #[OA\Get(
        path: '/billing/api/public/budgets/{budget}',
        summary: 'Consultar o orçamento (link assinado)',
        tags: ['Orçamento (cliente)'],
        parameters: [new OA\Parameter(name: 'budget', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Orçamento com itens, total e status'),
            new OA\Response(response: 403, description: 'Assinatura inválida ou expirada'),
            new OA\Response(response: 404, description: 'Orçamento não encontrado'),
        ]
    )]
    public function show(string $budget): JsonResponse
    {
        $found = $this->store->findBudget($budget);

        return $found
            ? response()->json(['data' => $found->toArray()])
            : response()->json(['message' => 'Orçamento não encontrado.'], 404);
    }

    #[OA\Get(
        path: '/billing/api/public/budgets/{budget}/approve',
        summary: 'Aprovar o orçamento (link assinado). Dispara billing.budget.approved e, na saga, a cobrança.',
        tags: ['Orçamento (cliente)'],
        parameters: [new OA\Parameter(name: 'budget', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Orçamento aprovado'),
            new OA\Response(response: 403, description: 'Assinatura inválida ou expirada'),
            new OA\Response(response: 404, description: 'Orçamento não encontrado'),
            new OA\Response(response: 422, description: 'Orçamento já decidido ou substituído por nova proposta'),
        ]
    )]
    public function approve(string $budget): JsonResponse
    {
        return $this->decide(fn () => $this->decision->approve($budget), 'Orçamento aprovado. Você receberá o link de pagamento.');
    }

    #[OA\Get(
        path: '/billing/api/public/budgets/{budget}/reject',
        summary: 'Recusar o orçamento (link assinado). final=1 cancela a OS; sem final, pede nova proposta.',
        tags: ['Orçamento (cliente)'],
        parameters: [
            new OA\Parameter(name: 'budget', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'final', in: 'query', required: false, schema: new OA\Schema(type: 'integer', enum: [0, 1])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Orçamento recusado'),
            new OA\Response(response: 403, description: 'Assinatura inválida ou expirada'),
            new OA\Response(response: 404, description: 'Orçamento não encontrado'),
            new OA\Response(response: 422, description: 'Orçamento já decidido ou substituído por nova proposta'),
        ]
    )]
    public function reject(Request $request, string $budget): JsonResponse
    {
        $final = $request->boolean('final');

        return $this->decide(
            fn () => $this->decision->reject($budget, $final),
            $final ? 'Orçamento recusado e OS cancelada.' : 'Orçamento recusado. Uma nova proposta será preparada.',
        );
    }

    private function decide(callable $action, string $message): JsonResponse
    {
        try {
            return response()->json(['message' => $message, 'data' => $action()->toArray()]);
        } catch (NotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
