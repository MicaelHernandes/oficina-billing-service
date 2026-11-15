# Convenções — oficina-billing-service

Billing Service da Fase 4 (orçamento + pagamento Mercado Pago), Laravel 13 com
DDD em `src/Billing`, banco DynamoDB, participante da saga orquestrada pelo
OS Service (oficina-api).

## Regras

- **Deploy só no GitHub Actions** (`deploy.yml`, branch `master`): terraform da
  `infra/` → ECR → Secret/ConfigMap → `kubectl apply -k k8s/overlays/prod`.
- Merge só via PR (`homolog` → `master`).
- Segredos nunca no repo: token interno e credenciais do MP vêm do Secrets
  Manager (`/oficina/billing/*`); o valor do MP é cadastrado fora do Terraform.
- Qualidade: `pint`, `phpstan` (nível 5), `pest` com cobertura mínima de 80%.
  Os testes de Feature precisam do DynamoDB Local (`DYNAMODB_ENDPOINT`).
- Contrato das mensagens espelha `src/Saga/Domain/Messages` do oficina-api:
  mudou um tipo/payload lá, muda aqui (`MessageType`, `Envelope`).
- Actions do GitHub fixadas por SHA (SonarCloud githubactions:S7637).

## Estrutura

- `Application/CommandHandler` — comandos da saga (inbox + estado + outbox
  numa `TransactWriteItems`).
- `Application/BudgetDecision`, `PaymentNotificationHandler` — cliente e webhook.
- `Infrastructure/Persistence/DynamoDbBillingStore` — tabela única (ver README).
- `Infrastructure/Payments` — Mercado Pago, validação do `x-signature`, gateway fake.
- Rotas sob `/billing/api` (o ALB compartilhado roteia `/billing` para cá).
