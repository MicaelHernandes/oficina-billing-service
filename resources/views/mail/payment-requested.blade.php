<p>Olá, {{ $budget->getCustomer()['name'] ?? 'cliente' }}.</p>

<p>Recebemos a aprovação do orçamento da sua Ordem de Serviço <strong>#{{ $budget->getOsId() }}</strong>.</p>

<p>Valor: <strong>R$ {{ number_format($payment->getAmount(), 2, ',', '.') }}</strong></p>

<p><a href="{{ $payment->getCheckoutUrl() }}">Pagar agora</a></p>

<p>Assim que o pagamento for confirmado, o serviço entra na fila de execução.</p>
