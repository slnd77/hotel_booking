{{-- Room Invoice; a Room Proforma Invoice until the invoice is generated at check-out. --}}
@extends('prints.booking.layout')

@php
    $money = fn (float $amount): string => $currency.' '.number_format($amount, 2);
    $toggles = $settings['toggles'];
    $detailed = $settings['invoice_template'] === 'detailed_view';
    // The reported check-in / check-out (with its time when the setting is on); the stay date until it happens.
    $stamp = fn ($date, $reportedAt): string => $reportedAt
        ? $reportedAt->format('d-M-Y').($toggles['checkin_checkout_time'] ? ' ; '.$reportedAt->format('g:i a') : '')
        : $date->format('d-M-Y');
    // Invoice Settings → Checkin/Checkout Preference: the property's check-out time, or when the desk marked it.
    $checkOutStamp = $settings['checkin_checkout_preference'] === 'property_checkout_time'
        ? $booking['check_out']->format('d-M-Y').($toggles['checkin_checkout_time'] && $booking['check_out_time'] ? ' ; '.\Illuminate\Support\Carbon::parse($booking['check_out_time'])->format('g:i a') : '')
        : $stamp($booking['check_out'], $booking['checked_out_at']);
    $series = $settings['invoice_series'];
    $company = $guest['company'] ?? null;

    // Invoice Settings → Invoice Billing Methods: one invoice for the booking ("Consolidate at Billing
    // Preference"), or one per room from its own folio ("Individual Invoices").
    $sections = $invoiceSections ?: [[
        'label' => null,
        'rooms' => $rooms,
        'room_total' => $booking['room_total'],
        'discount' => $booking['discount'],
        'taxes' => $booking['taxes'],
        'taxLines' => $taxLines,
        'adjustments' => 0.0,
        'total' => $booking['total'],
        'payments' => $payments,
        'net_payable' => round($booking['total'] - collect($payments)->sum('amount'), 2),
    ]];
    // Invoice Settings → Billing Method → Invoice Type.
    $typeLine = $settings['invoice_type'] === 'consolidated_invoice'
        ? 'Consolidated invoice (room and outlet charges)'
        : 'Room charges only — outlet charges are invoiced separately';
@endphp

@section('styles')
    .invoice-title { text-align: center; font-size: 20px; margin-bottom: 4px; }
    .invoice-type { text-align: center; font-size: 11px; color: #777; margin-bottom: 10px; }
    .invoice-section + .invoice-section { break-before: page; margin-top: 40px; padding-top: 30px; border-top: 2px dashed #ccc; }
    .top { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 14px; border-bottom: 1px solid #ccc; }
    .lines th { padding: 6px 8px; background: #eee; font-size: 12px; }
    .lines td { padding: 6px 8px; vertical-align: top; }
    .charges { background: #eee; }
    .charges td { padding: 3px 8px; }
    .label { font-weight: 700; }
    @media print { .invoice-section + .invoice-section { margin-top: 0; padding-top: 0; border-top: 0; } }
@endsection

@section('content')
    @foreach ($sections as $sectionIndex => $section)
        <div class="invoice-section">
            @if ($title)
                <div class="invoice-title">{{ $title }}@if ($section['label']) — {{ $section['label'] }}@endif</div>
            @endif
            <div class="invoice-type">
                @if ($section['label'])
                    Individual invoice {{ $sectionIndex + 1 }} of {{ count($sections) }} ·
                @endif
                {{ $typeLine }}
            </div>

            <div class="top">
                <div>
                    @if ($settings['invoice_logo'])
                        <img src="{{ $settings['invoice_logo'] }}" alt="{{ $property['name'] }} logo" style="display: block; max-height: 70px; max-width: 220px; margin-bottom: 8px">
                    @endif
                    <div class="bold" style="text-transform: uppercase">{{ $property['name'] }}</div>
                    @if ($property['legal_name'])
                        <div>{{ $property['legal_name'] }}</div>
                    @endif
                    @if ($settings['parent_company'])
                        <div class="small muted">A unit of {{ $settings['parent_company'] }}</div>
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
                    @if ($invoice)
                        <div><span class="label small">Invoice No. :</span> {{ $invoice['number'] }}@if ($section['label'])-{{ $sectionIndex + 1 }}@endif</div>
                        <div>{{ $invoice['generated_at']?->format('d-M-Y') }}</div>
                    @else
                        <div class="muted small">Invoice not generated yet</div>
                        <div>{{ $printedAt->format('d-M-Y') }}</div>
                    @endif
                    <div style="margin-top: 10px"><span class="label">Reservation ID</span> : {{ $booking['ref'] }}</div>
                    @if ($toggles['place_of_supply'] && $property['state'])
                        <div class="label">Place Of Supply : {{ $property['state'] }}</div>
                    @endif
                    <div><span class="label">Vendor Name</span> : {{ $booking['source'] }} Booking</div>
                    <div><span class="label">Ref ID</span> : {{ $booking['id'] }}</div>
                </div>
            </div>

            <div class="bold small" style="margin: 26px 0 6px; padding-bottom: 6px; border-bottom: 1px solid #ccc">GUEST DETAILS</div>
            <div style="display: flex; justify-content: space-between; gap: 24px">
                <div>
                    <div><span class="label">Name</span> : {{ $guest['name'] ?? '—' }}</div>
                    <div><span class="label">Contact</span> : {{ $guest['phone'] ?? '' }}</div>
                    <div><span class="label">Address</span> : {{ $guest['address'] ?? '' }}</div>
                    <div><span class="label">No. of Rooms</span> : {{ count($section['rooms']) }}</div>
                    {{-- The guest's company, with its registration number under Invoice Settings → Co. Registration No. --}}
                    @if ($company)
                        @if ($company['name'])
                            <div style="margin-top: 6px"><span class="label">Company</span> : {{ $company['name'] }}</div>
                        @endif
                        @if ($company['registration_no'])
                            <div><span class="label">{{ $settings['co_registration_label'] }}</span> : {{ $company['registration_no'] }}</div>
                        @endif
                        @if ($company['address'])
                            <div><span class="label">Company Address</span> : {{ $company['address'] }}</div>
                        @endif
                    @endif
                </div>
                <div class="right">
                    <div><span class="label">Email :</span> {{ $guest['email'] ?? '' }}</div>
                    <div><span class="label">Check-In :</span>{{ $stamp($booking['check_in'], $booking['checked_in_at']) }}</div>
                    <div><span class="label">Check-Out :</span>{{ $checkOutStamp }}</div>
                </div>
            </div>

            <table class="lines" style="margin-top: 36px">
                <thead>
                    <tr>
                        <th>Room No.</th>
                        <th>Rooms</th>
                        <th class="center">Nights</th>
                        <th class="center">Adults</th>
                        <th class="center">Child</th>
                        <th class="right">Arrival Date</th>
                        <th class="right">Departure Date</th>
                        <th class="right">Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['rooms'] as $room)
                        <tr>
                            <td>
                                {{ $room['room_number'] ?? 'Unassigned' }}
                                @if ($usesGst)
                                    <br>(SAC Code: {{ $sacCode }})
                                @endif
                            </td>
                            <td>{{ $room['category'] }}({{ $room['meal_plan'] }})</td>
                            <td class="center">{{ $room['nights'] }}</td>
                            <td class="center">{{ $room['adults'] }}</td>
                            <td class="center">{{ $room['children'] }}</td>
                            <td class="right nowrap">{{ $room['check_in']->format('jS M Y') }}</td>
                            <td class="right nowrap">{{ $room['check_out']->format('jS M Y') }}</td>
                            <td class="right nowrap">{{ $money($room['amount']) }}</td>
                        </tr>
                        @if ($detailed)
                            @for ($night = 0; $night < $room['nights']; $night++)
                                <tr class="small muted">
                                    <td></td>
                                    <td colspan="4">Room charge — {{ $room['check_in']->copy()->addDays($night)->format('d-M-Y') }}</td>
                                    <td colspan="3" class="right">{{ $money($room['rate']) }}</td>
                                </tr>
                            @endfor
                        @endif
                    @endforeach
                </tbody>
            </table>

            <table class="charges">
                <tr><td style="width: 40%"></td><td style="font-size: 15px">Room Charges</td><td class="right">{{ $money($section['room_total']) }}</td></tr>
                @if ($section['discount'] > 0)
                    <tr><td></td><td>Discount</td><td class="right">- {{ $money($section['discount']) }}</td></tr>
                @endif
                <tr class="bold small"><td></td><td>SUBTOTAL</td><td class="right">{{ $money($section['room_total'] - $section['discount']) }}</td></tr>
                @foreach ($section['taxLines'] as $line)
                    <tr><td></td><td>{{ $line['label'] }} for {{ $money($line['taxable']) }}</td><td class="right">{{ $money($line['amount']) }}</td></tr>
                @endforeach
                <tr class="bold small"><td></td><td>TOTAL TAXES</td><td class="right">{{ $money($section['taxes']) }}</td></tr>
                @if (abs($section['adjustments']) >= 0.01)
                    <tr><td></td><td>Adjustments &amp; transfers</td><td class="right">{{ $money($section['adjustments']) }}</td></tr>
                @endif
                <tr class="bold small"><td></td><td style="padding-bottom: 10px">TOTAL AMOUNT</td><td class="right" style="padding-bottom: 10px">{{ $money($section['total']) }}</td></tr>
            </table>

            <table class="lines avoid-break" style="margin-top: 40px">
                <thead>
                    <tr><th>S.No</th><th>Payments &amp; Advances</th><th>Receipt No.</th><th>Date</th><th class="right">Tagged Amount</th></tr>
                </thead>
                <tbody>
                    @foreach ($section['payments'] as $index => $payment)
                        <tr>
                            <td>{{ $index + 1 }}.</td>
                            <td>{{ $payment['mode'] }}</td>
                            <td>{{ $payment['receipt_number'] }}</td>
                            <td>{{ $payment['date']?->format('d-M-Y ; g:i:s a') }}</td>
                            <td class="right">{{ $money($payment['amount']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="charges">
                        <td colspan="3"></td>
                        <td class="bold small" style="padding: 8px">{{ $section['net_payable'] < 0 ? 'CREDIT DUE TO GUEST' : 'NET PAYABLE BY GUEST' }}</td>
                        <td class="right bold" style="padding: 8px">{{ $money(abs($section['net_payable'])) }}</td>
                    </tr>
                    <tr>
                        <td colspan="5" style="font-size: 15px; padding: 10px 8px">Thank You for staying with us.</td>
                    </tr>
                </tbody>
            </table>

            @if ($settings['guest_liability_enabled'] && filled($settings['guest_liability_text']))
                <p class="small" style="margin-top: 18px; white-space: pre-line">{{ $settings['guest_liability_text'] }}</p>
            @endif

            <div class="avoid-break small" style="display: flex; justify-content: space-between; margin-top: 60px">
                <div>
                    @if ($toggles['show_footer_signature'])
                        <div class="bold">SIGNATURE</div>
                        <div class="bold" style="margin-top: 6px">({{ $guest['name'] ?? '' }})</div>
                    @endif
                    <div style="margin-top: 6px"><span class="bold">Printed on :</span> {{ $printedAt->format('d-M-Y ; g:i:s a') }}</div>
                </div>
                <div class="right">
                    @if ($invoice && $toggles['invoice_generation_date'])
                        <div><span class="bold">Generated on :</span>{{ $invoice['generated_at']?->format('d-M-Y ; g:i:s a') }}</div>
                        <div><span class="bold">Generated by :</span>{{ $invoice['generated_by'] }}</div>
                    @endif
                    {{-- Invoice Settings → Digital Signature: the property's authorised signature. --}}
                    @if ($settings['digital_signature'])
                        <img src="{{ $settings['digital_signature'] }}" alt="Authorised signature" style="display: block; margin: 14px 0 4px auto; max-height: 60px; max-width: 200px">
                        <div class="bold">For {{ $property['name'] }}</div>
                        <div class="muted">Authorised Signatory</div>
                    @endif
                </div>
            </div>

            {{-- Invoice Settings → Invoice / OR Template Setting. --}}
            @if (filled($series['range'] ?? null) || filled($series['permit_no'] ?? null) || filled($series['owned_by'] ?? null))
                <div class="small muted" style="margin-top: 18px">
                    @if (filled($series['range'] ?? null))
                        <div>Invoice series: {{ $series['range'] }}</div>
                    @endif
                    @if (filled($series['permit_no'] ?? null))
                        <div>{{ $series['permit_label'] }} {{ $series['permit_no'] }}@if (filled($series['permit_date'] ?? null)), issued {{ $series['permit_date'] }}@endif</div>
                    @endif
                    @if (filled($series['owned_by'] ?? null))
                        <div>Owned by: {{ $series['owned_by'] }}</div>
                    @endif
                </div>
            @endif

            @unless ($invoice)
                <p class="center bold" style="margin-top: 24px; padding-top: 10px; border-top: 1px dashed #bbb">This is not a tax invoice</p>
            @endunless
        </div>
    @endforeach
@endsection
