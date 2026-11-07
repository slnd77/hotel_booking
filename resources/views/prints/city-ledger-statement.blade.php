{{-- A city ledger account's statement: balance brought forward, the period's entries with the running balance, and the ageing. --}}
@extends('prints.booking.layout')

@php
    $money = fn (float $amount): string => $currency.' '.number_format($amount, 2);
@endphp

@section('styles')
    .statement-title { text-align: center; font-size: 20px; margin-bottom: 14px; }
    .top { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 14px; border-bottom: 1px solid #ccc; }
    .lines th { padding: 6px 8px; background: #eee; font-size: 12px; }
    .lines td { padding: 5px 8px; vertical-align: top; border-bottom: 1px dashed #ddd; }
    .label { font-weight: 700; }
@endsection

@section('content')
    <div class="statement-title">{{ $title }}</div>

    <div class="top">
        <div>
            <div class="bold" style="text-transform: uppercase">{{ $property->name }}</div>
            @if ($property->property_legal_name)
                <div>{{ $property->property_legal_name }}</div>
            @endif
            @if ($property->property_contact_number)
                <div><span class="label">Ph :</span> {{ $property->property_contact_number }}</div>
            @endif
        </div>
        <div class="right">
            <div class="bold">{{ $account->name }}</div>
            @if ($account->code)
                <div><span class="label">Account :</span> {{ $account->code }}</div>
            @endif
            @if ($account->tax_number)
                <div><span class="label">GSTIN :</span> {{ $account->tax_number }}</div>
            @endif
            @if ($account->address)
                <div style="white-space: pre-line">{{ $account->address }}</div>
            @endif
            <div style="margin-top: 8px"><span class="label">Period :</span> {{ $period['from']->format('d-M-Y') }} to {{ $period['to']->format('d-M-Y') }}</div>
            <div><span class="label">Terms :</span> {{ $account->payment_terms_days }} days</div>
        </div>
    </div>

    <table class="lines" style="margin-top: 18px">
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Particulars</th>
                <th>Due</th>
                <th class="right">Debit</th>
                <th class="right">Credit</th>
                <th class="right">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td></td>
                <td></td>
                <td class="bold">Balance brought forward</td>
                <td></td>
                <td></td>
                <td></td>
                <td class="right bold nowrap">{{ $money($statement['opening']) }}</td>
            </tr>
            @foreach ($statement['entries'] as $entry)
                <tr>
                    <td class="nowrap">{{ \Illuminate\Support\Carbon::parse($entry['entry_date'])->format('d-M-Y') }}</td>
                    <td class="nowrap">{{ $entry['reference'] }}</td>
                    <td>{{ $entry['description'] }}</td>
                    <td class="nowrap">{{ $entry['due_date'] ? \Illuminate\Support\Carbon::parse($entry['due_date'])->format('d-M-Y') : '' }}</td>
                    <td class="right nowrap">{{ $entry['debit'] > 0 ? $money($entry['debit']) : '' }}</td>
                    <td class="right nowrap">{{ $entry['credit'] > 0 ? $money($entry['credit']) : '' }}</td>
                    <td class="right nowrap">{{ $money($entry['balance']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td></td>
                <td></td>
                <td class="bold">Closing balance</td>
                <td></td>
                <td></td>
                <td></td>
                <td class="right bold nowrap">{{ $money($statement['closing']) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="bold small" style="margin: 24px 0 6px">AGEING (AS ON TODAY)</div>
    <table class="lines">
        <thead>
            <tr>
                @foreach ($buckets as $label)
                    <th class="right">{{ $label }}</th>
                @endforeach
                <th class="right">Total due</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                @foreach (array_keys($buckets) as $bucket)
                    <td class="right nowrap">{{ $money($ageing['buckets'][$bucket]) }}</td>
                @endforeach
                <td class="right bold nowrap">{{ $money($ageing['balance']) }}</td>
            </tr>
        </tbody>
    </table>
@endsection
