{{-- A credit note against an invoice: the property's header, the invoice it corrects, and the credited amounts. --}}
@extends('prints.booking.layout')

@php
    $money = fn (float $amount): string => $currency.' '.number_format($amount, 2);
@endphp

@section('styles')
    .note-title { text-align: center; font-size: 20px; margin-bottom: 14px; }
    .top { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 14px; border-bottom: 1px solid #ccc; }
    .lines th { padding: 6px 8px; background: #eee; font-size: 12px; }
    .lines td { padding: 6px 8px; vertical-align: top; }
    .label { font-weight: 700; }
@endsection

@section('content')
    <div class="note-title">{{ $title }}</div>

    <div class="top">
        <div>
            <div class="bold" style="text-transform: uppercase">{{ $property['name'] }}</div>
            @if ($property['legal_name'])
                <div>{{ $property['legal_name'] }}</div>
            @endif
            <div>{{ $property['address'] }}</div>
            @if ($property['phone'])
                <div><span class="label">Ph :</span> {{ $property['phone'] }}</div>
            @endif
            @foreach ($property['statutory_ids'] as $statutoryId)
                <div><span class="label">{{ $statutoryId['display_name'] }} :</span> {{ $statutoryId['display_value'] }}</div>
            @endforeach
        </div>
        <div class="right nowrap">
            <div><span class="label small">Credit Note No. :</span> {{ $note->credit_note_number }}</div>
            <div>{{ $note->issue_date->format('d-M-Y') }}</div>
            <div style="margin-top: 10px"><span class="label">Against Invoice</span> : {{ $invoice->invoice_number }}</div>
            <div><span class="label">Invoice Date</span> : {{ $invoice->invoice_generation_date?->format('d-M-Y') }}</div>
            @if ($invoice->folio_number)
                <div><span class="label">Reservation ID</span> : {{ $invoice->folio_number }}</div>
            @endif
            @if ($property['state'])
                <div class="label">Place Of Supply : {{ $property['state'] }}</div>
            @endif
        </div>
    </div>

    <div class="bold small" style="margin: 26px 0 6px; padding-bottom: 6px; border-bottom: 1px solid #ccc">ISSUED TO</div>
    <div><span class="label">Name</span> : {{ $invoice->party_name ?: ($invoice->guest_name ?: '—') }}</div>
    @if ($invoice->party_gstin)
        <div><span class="label">GSTIN</span> : {{ $invoice->party_gstin }}</div>
    @endif

    <table class="lines" style="margin-top: 22px">
        <thead>
            <tr>
                <th>Particulars</th>
                <th class="right">Taxable Value</th>
                <th class="right">Tax</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr class="dashed">
                <td>{{ $note->reason }}</td>
                <td class="right nowrap">{{ $money((float) $note->taxable_amount) }}</td>
                <td class="right nowrap">{{ $money((float) $note->tax_amount) }}</td>
                <td class="right nowrap">{{ $money((float) $note->total) }}</td>
            </tr>
            <tr>
                <td class="bold">Total credited</td>
                <td></td>
                <td></td>
                <td class="right bold nowrap">{{ $money((float) $note->total) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="small muted" style="margin-top: 18px">
        Invoice {{ $invoice->invoice_number }} was for {{ $money((float) $invoice->invoice_amount) }} (taxable {{ $money((float) $invoice->taxable_amount) }}, tax {{ $money((float) $invoice->total_tax) }}).
        This note reduces it by {{ $money((float) $note->total) }}.
    </p>

    <p class="small muted" style="margin-top: 40px">Issued by {{ $note->creator?->name ?? 'Staff' }}</p>
@endsection
