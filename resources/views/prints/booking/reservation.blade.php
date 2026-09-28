{{-- Reservation (Internal / Guest) and Guest Bill Summary. --}}
@extends('prints.booking.layout')

@use('App\Enums\BookingPrintDocument')

@php
    $isInternal = $document === BookingPrintDocument::ReservationInternal;
    [$tncToggle, $policyToggle] = match ($document) {
        BookingPrintDocument::ReservationInternal => ['tnc_reservation_internal', 'cancellation_policy_reservation_internal'],
        BookingPrintDocument::ReservationGuest => ['tnc_reservation_guest', 'cancellation_policy_reservation_guest'],
        default => ['tnc_guest_agent_company', 'cancellation_policy_guest_agent_company'],
    };
    $paid = collect($payments)->sum('amount');
    // The Guest Bill Summary also lists the outlet (POS) bills charged to the rooms.
    $billOutlets = $document === BookingPrintDocument::BillSummary ? $outletBills : [];
    $outletTotal = collect($billOutlets)->sum('total');
    $addonsTotal = collect($addons)->sum('total');
    $money = fn (float $amount): string => number_format($amount, 2);
@endphp

@section('styles')
    .section-title { margin: 22px 0 8px; padding-bottom: 2px; border-bottom: 1px dashed #bbb; color: #555; font-size: 15px; font-weight: 700; }
    .header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 18px; }
    .brand { font-size: 22px; font-weight: 700; color: #8b1d1d; margin-bottom: 8px; }
    .band { display: grid; grid-template-columns: 1fr 1.1fr 110px; align-items: center; padding: 8px 0; border-bottom: 1px dashed #bbb; }
    .dates { display: flex; justify-content: space-around; align-items: center; padding-left: 12px; border-left: 1px dashed #bbb; text-align: center; }
    .dates .day { font-size: 20px; font-weight: 700; color: #111; }
    .stamp { justify-self: center; width: 86px; height: 86px; border: 3px solid #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #10b981; font-size: 11px; font-weight: 700; text-transform: uppercase; transform: rotate(-20deg); text-align: center; }
    .stamp.cancelled { border-color: #ef4444; color: #ef4444; }
    .figures { display: grid; grid-template-columns: 1fr 1fr 1fr; padding: 8px 0; border-bottom: 1px dashed #bbb; }
    .plan th, .plan td { padding: 6px 6px; vertical-align: top; }
    .totals { width: 300px; margin-left: auto; }
    .totals td { padding: 1px 0; }
    .summary-row { display: flex; justify-content: flex-end; gap: 40px; padding: 8px 0; border-bottom: 1px dashed #bbb; }
    .summary-row span:last-child { width: 110px; text-align: right; }
@endsection

@section('content')
    <div class="header">
        <div>
            <div class="brand">{{ $property['name'] }}</div>
            <div>{{ $property['address'] }}</div>
            @if ($property['phone'])
                <div>M: {{ $property['phone'] }}</div>
            @endif
        </div>
        <div class="right">
            @if ($title)
                <div style="font-size: 16px; text-transform: uppercase">{{ $title }}</div>
            @endif
            <div style="text-transform: uppercase">{{ $property['name'] }}</div>
        </div>
    </div>

    <div class="dashed bold muted" style="display: flex; justify-content: space-between; padding-bottom: 4px">
        <span>Reservation #:{{ $booking['ref'] }}</span>
        <span>REF. ID.{{ $booking['id'] }}</span>
    </div>

    <div class="band">
        <div>
            <div>{{ $guest['name'] ?? '—' }}</div>
            @if ($guest['phone'] ?? null)
                <div style="margin-top: 8px">{{ $guest['phone'] }}</div>
            @endif
        </div>
        <div>
            <div class="dates">
                <div><div class="day">{{ $booking['check_in']->format('d') }}</div><div>{{ strtoupper($booking['check_in']->format('M Y')) }}</div></div>
                <div class="muted">--- {{ $booking['nights'] }} ---</div>
                <div><div class="day">{{ $booking['check_out']->format('d') }}</div><div>{{ strtoupper($booking['check_out']->format('M Y')) }}</div></div>
            </div>
            <div class="muted" style="padding-left: 12px; margin-top: 4px">{{ $booking['room_summary'] }} Room(s)</div>
        </div>
        <div class="stamp {{ $booking['is_cancelled'] ? 'cancelled' : '' }}">{{ $booking['status'] }}</div>
    </div>

    <div class="figures">
        <div>Total Bill<br><span class="bold">{{ $currency }} {{ $money($booking['total'] + $addonsTotal + $outletTotal) }}/-</span></div>
        <div>Outstanding<br><span class="bold">{{ $currency }} {{ $money($booking['outstanding']) }}/-</span></div>
        <div class="small muted" style="align-self: end">{{ strtoupper($booking['source']) }}</div>
    </div>

    <div class="section-title">Plan Details</div>
    <table class="plan">
        <thead>
            <tr>
                <th>Room Category</th>
                <th>Nights</th>
                <th>Adults</th>
                <th>Children</th>
                <th>Infants</th>
                <th>Meal Plan</th>
                @if ($isInternal)
                    <th>Room</th>
                @endif
                <th class="right">Rate/Night</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rooms as $room)
                <tr>
                    <td>{{ $room['category'] }}</td>
                    <td>{{ $room['nights'] }}</td>
                    <td>{{ $room['adults'] }}</td>
                    <td>{{ $room['children'] }}</td>
                    <td>{{ $room['infants'] }}</td>
                    <td>{{ $room['meal_plan'] }}</td>
                    @if ($isInternal)
                        <td>
                            {{ $room['room_number'] ?? 'Unassigned' }}
                            @if ($usesGst)
                                <br><span class="small">(SAC Code: {{ $sacCode }})</span>
                            @endif
                        </td>
                    @endif
                    <td class="right">{{ $money($room['rate']) }}</td>
                    <td class="right">{{ $money($room['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 14px">
        <tr><td>Room Total</td><td class="right">{{ $money($booking['room_total']) }}</td></tr>
        @if ($booking['discount'] > 0)
            <tr><td>Discount</td><td class="right">({{ $money($booking['discount']) }})</td></tr>
        @endif
        {{-- A single saved-taxes line is already the "Taxes" row below. --}}
        @foreach (count($taxLines) > 1 || ($taxLines[0]['label'] ?? 'Taxes') !== 'Taxes' ? $taxLines : [] as $line)
            <tr><td>{{ $line['label'] }}</td><td class="right">{{ $currency }} {{ $money($line['amount']) }}</td></tr>
        @endforeach
        <tr><td>Taxes</td><td class="right">{{ $money($booking['taxes']) }}</td></tr>
        <tr><td>TOTAL</td><td class="right">{{ $money($booking['total']) }}</td></tr>
    </table>
    @if (count($addons))
        <div class="section-title">Add-on Services</div>
        <table class="plan">
            <thead>
                <tr><th>Service</th><th>Pricing</th><th>Qty</th><th class="right">Rate</th><th class="right">Amount</th><th class="right">Tax</th><th class="right">Total</th></tr>
            </thead>
            <tbody>
                @foreach ($addons as $addon)
                    <tr class="avoid-break">
                        <td>{{ $addon['name'] }}</td>
                        <td>{{ $addon['pricing'] }}@if ($addon['multiplier'] > 1) (× {{ $addon['multiplier'] }})@endif</td>
                        <td>{{ $addon['quantity'] }}</td>
                        <td class="right">{{ $money($addon['unit_price']) }}</td>
                        <td class="right">{{ $money($addon['amount']) }}</td>
                        <td class="right">{{ $money($addon['tax']) }}</td>
                        <td class="right">{{ $money($addon['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <table class="totals" style="margin-top: 8px">
            <tr><td>Add-ons Total</td><td class="right">{{ $money($addonsTotal) }}</td></tr>
        </table>
    @endif

    @if (count($billOutlets))
        <div class="section-title">Outlet Bills</div>
        <table class="plan">
            <thead>
                <tr><th>Date</th><th>Outlet</th><th>Bill No.</th><th>Room</th><th>Items</th><th class="right">Amount</th><th class="right">Tax</th><th class="right">Total</th></tr>
            </thead>
            <tbody>
                @foreach ($billOutlets as $outletBill)
                    <tr class="avoid-break">
                        <td class="nowrap">{{ $outletBill['date']->format('d-M-Y') }}</td>
                        <td>{{ $outletBill['outlet'] }}</td>
                        <td>#{{ $outletBill['bill_number'] }}</td>
                        <td>{{ $outletBill['room'] ?? '—' }}</td>
                        <td class="small">{{ $outletBill['items'] }}</td>
                        <td class="right">{{ $money($outletBill['sub_total']) }}</td>
                        <td class="right">{{ $money($outletBill['tax']) }}</td>
                        <td class="right">{{ $money($outletBill['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <table class="totals" style="margin-top: 8px">
            <tr><td>Outlet Bills Total</td><td class="right">{{ $money($outletTotal) }}</td></tr>
        </table>
    @endif

    <div class="dashed" style="margin-top: 6px"></div>
    @if (count($billOutlets) || count($addons))
        <div class="summary-row bold"><span>Room Charges</span><span>{{ $money($booking['total']) }}</span></div>
    @endif
    @if (count($addons))
        <div class="summary-row bold"><span>Add-ons</span><span>{{ $money($addonsTotal) }}</span></div>
    @endif
    @if (count($billOutlets))
        <div class="summary-row bold"><span>Outlet Bills</span><span>{{ $money($outletTotal) }}</span></div>
    @endif
    <div class="summary-row bold"><span>Taxes</span><span>{{ $money($booking['taxes']) }}</span></div>
    <div class="summary-row bold"><span>Payments</span><span>{{ $money($paid) }}</span></div>
    <div class="summary-row bold"><span>Balance Payable</span><span>{{ $money($booking['outstanding']) }}</span></div>

    <div class="section-title">Payment Summary</div>
    @if (count($payments))
        <table class="plan">
            <thead><tr><th>Mode</th><th>Receipt No.</th><th>Date</th><th class="right">Amount</th></tr></thead>
            <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment['mode'] }}</td>
                        <td>{{ $payment['receipt_number'] }}</td>
                        <td>{{ $payment['date']?->format('d-M-Y') }}</td>
                        <td class="right">{{ $money($payment['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="section-title">Special Instructions</div>
    @if ($booking['special_instructions'])
        <div style="white-space: pre-line">{{ $booking['special_instructions'] }}</div>
    @endif

    @if ($isInternal)
        <div class="section-title">Internal Notes</div>
        @if ($booking['internal_notes'])
            <div style="white-space: pre-line">{{ $booking['internal_notes'] }}</div>
        @endif
    @endif

    <div class="section-title">Inclusions</div>
    @foreach ($inclusions as $inclusion)
        <div class="avoid-break" style="margin-bottom: 8px">
            <div style="color: #000">{{ $inclusion['category'] }}</div>
            <div class="rich small">{!! $inclusion['html'] !!}</div>
        </div>
    @endforeach

    @if ($settings['toggles'][$policyToggle] && count($cancellationPolicy))
        <div class="section-title">Cancellation Policy</div>
        <ul class="small" style="margin: 0; padding-left: 18px">
            @foreach ($cancellationPolicy as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    @endif

    @if ($settings['toggles'][$tncToggle] && $termsAndConditions !== '')
        <div class="section-title">Terms and Conditions</div>
        <div class="rich small">{!! $termsAndConditions !!}</div>
    @endif

    <div class="small" style="margin-top: 18px">
        @if ($printedBy)
            <div><span class="bold">Printed By:</span> {{ $printedBy }}</div>
        @endif
        <div><span class="bold">Created on:</span> {{ $booking['created_at']?->format('d-M-Y g:i:s a') }}</div>
        <div><span class="bold">Type of Reservation:</span> {{ $booking['source'] }}</div>
    </div>
@endsection
