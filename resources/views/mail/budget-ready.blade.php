@php($b = $budget->toArray())
<p>Olá, {{ $b['customer']['name'] ?? 'cliente' }}.</p>

<p>O orçamento da sua Ordem de Serviço <strong>#{{ $b['os_id'] }}</strong> (revisão {{ $b['revision'] }}) está pronto.</p>

<table cellpadding="4">
    @foreach (array_merge($b['services'], $b['parts']) as $item)
        <tr>
            <td>{{ $item['service_name'] ?? $item['part_name'] ?? 'Item' }}</td>
            <td>{{ $item['quantity'] ?? 1 }} ×</td>
            <td>R$ {{ number_format((float) ($item['unit_price'] ?? 0), 2, ',', '.') }}</td>
        </tr>
    @endforeach
    <tr>
        <td colspan="2"><strong>Total</strong></td>
        <td><strong>R$ {{ number_format($b['total_amount'], 2, ',', '.') }}</strong></td>
    </tr>
</table>

<p>
    <a href="{{ $approveUrl }}">Aprovar orçamento</a> ·
    <a href="{{ $rejectUrl }}">Recusar e pedir nova proposta</a> ·
    <a href="{{ $cancelUrl }}">Recusar e cancelar a OS</a>
</p>

<p>Ao aprovar, você recebe o link de pagamento (Mercado Pago). O serviço começa após a confirmação do pagamento.</p>
