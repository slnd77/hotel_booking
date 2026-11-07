@php
    // Printable width of the paper: thermal rolls lose a few millimetres to the print head margins.
    $width = ['thermal_58' => '48mm', 'thermal_80' => '72mm'][$paper] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        @if ($width)
            @page { size: {{ $paper === 'thermal_58' ? '58mm' : '80mm' }} auto; margin: 0; }
        @else
            @page { size: A4; margin: 12mm; }
        @endif
        * { box-sizing: border-box; }
        body { margin: 0; background: #e5e5e5; color: #000; font: {{ $width ? '12px/1.35 "Courier New", Courier, monospace' : '13px/1.45 "Segoe UI", Arial, sans-serif' }}; }
        .sheet { width: {{ $width ?? '210mm' }}; margin: 16px auto; padding: {{ $width ? '2mm' : '12mm' }}; background: #fff; box-shadow: 0 1px 6px rgba(0, 0, 0, .2); }
        .toolbar { position: sticky; top: 0; display: flex; justify-content: center; gap: 8px; padding: 10px; background: #1b1b18; }
        .toolbar button { padding: 6px 16px; border: 0; border-radius: 4px; font: 600 12px/1 Arial, sans-serif; text-transform: uppercase; cursor: pointer; }
        .toolbar .primary { background: #ea580c; color: #fff; }
        .toolbar .secondary { background: #fff; color: #1b1b18; }
        h1, h2, p { margin: 0; }
        h1 { font-size: {{ $width ? '15px' : '20px' }}; }
        h2 { font-size: {{ $width ? '13px' : '15px' }}; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-weight: 700; }
        th, td { padding: {{ $width ? '1px 0' : '4px 6px' }}; vertical-align: top; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .large { font-size: {{ $width ? '18px' : '24px' }}; font-weight: 700; }
        .muted { color: #555; }
        .rule { border-top: 1px dashed #000; margin: 4px 0; }
        .lines th { border-bottom: 1px dashed #000; }
        .void { margin: 4px 0; font-size: 20px; font-weight: 800; letter-spacing: 2px; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: {{ $width ?? 'auto' }}; margin: 0; box-shadow: none; padding: {{ $width ? '0 1mm' : '0' }}; }
        }
    </style>
</head>
<body>
    @unless ($pdf ?? false)
        <div class="toolbar">
            <button type="button" class="primary" onclick="window.print()">Print</button>
            <button type="button" class="secondary" onclick="window.close()">Close</button>
        </div>
    @endunless

    <div class="sheet">
        @yield('content')
    </div>

    @if ($autoPrint)
        <script>
            window.addEventListener('load', () => setTimeout(() => window.print(), 300));
        </script>
    @endif
</body>
</html>
