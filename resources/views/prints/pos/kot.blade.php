@extends('prints.pos.layout', ['title' => "{$data['outlet']} KOT #{$data['kot_number']}"])

@section('content')
    <div class="center">
        <h2>{{ $data['outlet'] }}</h2>
        <p class="large">{{ $data['is_cancellation'] ? 'CANCEL ' : '' }}KOT #{{ $data['kot_number'] }}</p>
    </div>

    <div class="rule"></div>
    <table>
        <tr><td>Bill #{{ $data['bill_number'] }}</td><td class="right">{{ $data['date'] }}</td></tr>
        @if ($data['table_number'])
            <tr class="bold"><td colspan="2">Table: {{ $data['table_number'] }}</td></tr>
        @endif
        @if ($data['room'])
            <tr><td colspan="2">Room: {{ $data['room'] }}</td></tr>
        @endif
        @if ($data['guest_name'])
            <tr><td colspan="2">Guest: {{ $data['guest_name'] }}</td></tr>
        @endif
    </table>
    <div class="rule"></div>

    <table>
        @foreach ($data['lines'] as $line)
            <tr class="large">
                <td style="width: 1%; white-space: nowrap; padding-right: 8px">{{ $line['quantity'] < 0 ? 'CANCEL ' : '' }}{{ abs($line['quantity']) }} x</td>
                <td>{{ $line['name'] }}</td>
            </tr>
        @endforeach
    </table>
    <div class="rule"></div>

    @if ($data['notes'])
        <p class="bold">{{ $data['notes'] }}</p>
    @endif
    @if ($data['waiter'])
        <p class="muted">By: {{ $data['waiter'] }}</p>
    @endif
@endsection
