@extends('prints.pos.layout', ['title' => "{$data['outlet']} bill #{$data['bill_number']}"])

@php($money = fn (float $amount): string => number_format($amount, 2))

@section('content')
    <div class="center">
        <h1>{{ $data['property']['name'] }}</h1>
        @if ($data['property']['address'] !== '')
            <p class="muted">{{ $data['property']['address'] }}</p>
        @endif
        @foreach ($data['property']['statutory_ids'] as $statutoryId)
            <p>{{ $statutoryId['label'] }}: {{ $statutoryId['value'] }}</p>
        @endforeach
        <h2 style="margin-top: 4px">{{ $data['outlet'] }}</h2>
        <p class="bold">{{ strtoupper($data['title']) }}</p>
        @if ($data['is_void'])
            <p class="void">*** VOID ***</p>
        @endif
    </div>

    <div class="rule"></div>
    <table>
        <tr><td>Bill #{{ $data['bill_number'] }}</td><td class="right">{{ $data['date'] }}</td></tr>
        @if ($data['table_number'])
            <tr><td colspan="2">Table: {{ $data['table_number'] }}</td></tr>
        @endif
        @if ($data['guest_name'])
            <tr><td colspan="2">Guest: {{ $data['guest_name'] }}</td></tr>
        @endif
    </table>
    <div class="rule"></div>

    <table class="lines">
        <thead>
            <tr>
                <th>Item</th>
                <th class="right">Qty</th>
                @if ($paper !== 'thermal_58')
                    <th class="right">Rate</th>
                @endif
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data['lines'] as $line)
                <tr>
                    <td>{{ $line['name'] }}</td>
                    <td class="right">{{ $line['quantity'] }}</td>
                    @if ($paper !== 'thermal_58')
                        <td class="right">{{ $money($line['unit_price']) }}</td>
                    @endif
                    <td class="right">{{ $money($line['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="rule"></div>

    <table>
        <tr><td>Sub Total</td><td class="right">{{ $money($data['sub_total']) }}</td></tr>
        @if ($data['discount'] > 0)
            <tr><td>Discount</td><td class="right">-{{ $money($data['discount']) }}</td></tr>
        @endif
        @foreach ($data['taxes'] as $tax)
            <tr><td>{{ $tax['label'] }}</td><td class="right">{{ $money($tax['amount']) }}</td></tr>
        @endforeach
        <tr class="bold"><td>Total ({{ $data['currency'] }})</td><td class="right">{{ $money($data['total']) }}</td></tr>
    </table>

    @if ($data['settlement'] !== [])
        <div class="rule"></div>
        <table>
            @foreach ($data['settlement'] as $settlement)
                <tr><td>{{ $settlement['label'] }}</td><td class="right">{{ $money($settlement['amount']) }}</td></tr>
            @endforeach
        </table>
    @endif

    @if ($data['void_reason'])
        <p>Void: {{ $data['void_reason'] }}</p>
    @endif
    @if ($data['notes'])
        <p>{{ $data['notes'] }}</p>
    @endif
    @if ($data['cashier'])
        <p class="muted">Cashier: {{ $data['cashier'] }}</p>
    @endif
    <p class="center" style="margin-top: 8px">Thank you!</p>
@endsection
