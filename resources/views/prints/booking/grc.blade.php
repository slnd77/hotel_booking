{{-- Guest Reservation Card: a blank card for the guest to fill in at check-in. Fields follow Property → GRC settings. --}}
@extends('prints.booking.layout')

@php
    $shows = fn (string $field): bool => $grc['fields'][$field] ?? true;
    $sections = [
        'PERSONAL DETAILS' => ['Name', 'Date of Birth', 'Email', 'Place of Birth', 'Mobile', 'Gender', 'Nationality', 'Present Address', 'Permanent Address'],
        'GUEST DOCUMENT DETAILS' => ['Reference Address', 'ID Type', 'ID Number', 'ID Issued At', 'ID Issued Date', 'ID Expiry'],
        'ACCOMMODATION' => ['Arrival Date', 'Arrival Time', 'Departure Date', 'Departure Time', 'Nights', 'PAX', 'Room', 'Rate Type', 'Booking Source', 'Room Number'],
        'INTERNATIONAL GUEST DETAILS' => ['Passport Number', 'Passport Issue Date', 'Passport Issue Place', 'Visa Number', 'Visa Type', 'Visa Issue Date', 'Visa Valid Till Date', 'Visa Issue Place', 'Purpose of Visit'],
        'EXTRA DETAILS' => ['Reason to Visit', 'Arrived From', 'Date and Time of Arrival in Country', 'Going To', 'Vehicle Number'],
    ];
    $labels = [
        'ID Number' => 'ID No.', 'ID Issued At' => 'Issued at', 'ID Issued Date' => 'Issued Date', 'ID Expiry' => 'Expiry',
        'Departure Date' => 'Dep. Date', 'Departure Time' => 'Dep. Time', 'PAX' => 'Pax', 'Passport Issue Date' => 'Date of Issue of Passport',
        'Passport Issue Place' => 'Place Of Issue of Passport', 'Visa Type' => 'Type of Visa', 'Visa Issue Date' => 'Date of Issue of Visa',
        'Visa Valid Till Date' => 'Visa Valid Till date', 'Visa Issue Place' => 'Place Of Issue of Visa',
    ];
    $wide = ['Present Address', 'Permanent Address', 'Nationality', 'Reference Address', 'Date and Time of Arrival in Country'];
    $notes = ['Reference Address' => '(Applicable for other country residents)', 'Date and Time of Arrival in Country' => '(Applicable for other country residents)'];
@endphp

@section('styles')
    .grc-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
    .grc-title { font-size: 24px; }
    .grc-section { margin-top: 12px; padding-bottom: 3px; border-bottom: 2px solid #222; font-size: 15px; font-weight: 700; }
    .grc-grid { display: grid; grid-template-columns: 1fr 1fr; column-gap: 32px; row-gap: 10px; margin-top: 10px; }
    .grc-field { display: flex; align-items: flex-end; gap: 10px; }
    .grc-field.wide { grid-column: 1 / -1; }
    .grc-field .name { font-weight: 700; white-space: nowrap; }
    .grc-field .line { flex: 1; min-width: 60px; border-bottom: 1px solid #555; height: 16px; }
    .grc-field .note { font-size: 11px; color: #555; }
    .choice { display: inline-flex; align-items: center; gap: 4px; margin-right: 12px; }
    .choice i { display: inline-block; width: 11px; height: 11px; border: 1px solid #555; border-radius: 50%; }
    .rule { border-bottom: 1px solid #555; height: 28px; }
@endsection

@section('content')
    <div class="grc-head">
        <div>
            <div class="bold" style="font-size: 18px; color: #8b1d1d">{{ $property['name'] }}</div>
            <div style="margin-top: 6px">{{ $property['address'] }}</div>
            @if ($property['phone'])
                <div><span class="bold">M:</span> {{ $property['phone'] }}</div>
            @endif
        </div>
        <div class="right">
            <div class="grc-title">{{ $title }}</div>
            <div><span class="bold">Reservation ID</span> {{ $booking['ref'] }}</div>
        </div>
    </div>

    @foreach ($sections as $heading => $fields)
        @php $visible = array_values(array_filter($fields, $shows)); @endphp
        @if (count($visible))
            <div class="grc-section">{{ $heading }}</div>
            <div class="grc-grid">
                @foreach ($visible as $field)
                    <div class="grc-field {{ in_array($field, $wide, true) ? 'wide' : '' }}">
                        <span class="name">{{ $labels[$field] ?? $field }}</span>
                        @if ($field === 'Gender')
                            <span><span class="choice"><i></i> Male</span><span class="choice"><i></i> Female</span><span class="choice"><i></i> Others</span></span>
                        @else
                            <span class="line"></span>
                        @endif
                        @if (isset($notes[$field]))
                            <span class="note">{{ $notes[$field] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endforeach

    <div class="grc-field" style="margin-top: 10px">
        <span class="name">Subscribe to Newsletter / Marketing emails</span>
        <span style="display: inline-block; width: 12px; height: 12px; border: 1px solid #555"></span>
    </div>

    @if ($shows('Notes'))
        <div class="grc-section">NOTES</div>
        <div class="rule"></div>
        <div class="rule"></div>
    @endif

    @if ($shows('Terms & Conditions') && $grc['terms'] !== '')
        <div class="avoid-break" style="margin-top: 18px">
            <div class="bold">Terms and Conditions</div>
            <div class="rich small">{!! $grc['terms'] !!}</div>
        </div>
    @endif

    @if ($shows('House Rules') && $grc['house_rules'] !== '')
        <div class="avoid-break" style="margin-top: 14px">
            <div class="bold">House Rules</div>
            <div class="rich small">{!! $grc['house_rules'] !!}</div>
        </div>
    @endif

    <div class="avoid-break small" style="display: flex; justify-content: space-between; margin-top: 30px">
        <div>
            @if ($printedBy)
                <div><span class="bold">Printed By :</span> {{ $printedBy }}</div>
            @endif
            <div><span class="bold">Printed on :</span> {{ $printedAt->format('d-M-Y ; g:i:s a') }}</div>
            <div class="bold" style="margin-top: 40px">STAFF SIGNATURE</div>
        </div>
        <div class="bold" style="text-align: center">
            {{-- Signed during web check-in. --}}
            @if ($guestSignature ?? null)
                <img src="{{ $guestSignature }}" alt="Guest signature" style="display: block; max-height: 60px; max-width: 220px; margin: 0 auto 4px">
            @endif
            GUEST SIGNATURE
        </div>
    </div>
@endsection
