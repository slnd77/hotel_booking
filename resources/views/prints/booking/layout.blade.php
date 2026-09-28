<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $document->label() }} — {{ $booking['ref'] }}</title>
    <style>
        @page { size: A4; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; background: #e5e5e5; color: #333; font: 13px/1.45 "Open Sans", "Segoe UI", Arial, sans-serif; }
        .sheet { position: relative; width: 210mm; min-height: 297mm; margin: 16px auto; padding: 10mm; background: #fff; box-shadow: 0 1px 6px rgba(0, 0, 0, .2); overflow: hidden; }
        .frame { position: relative; min-height: calc(297mm - 20mm); padding: 8mm 9mm; border: 1px solid #999; }
        .toolbar { position: sticky; top: 0; z-index: 10; display: flex; justify-content: center; gap: 8px; padding: 10px; background: #1b1b18; }
        .toolbar button, .toolbar a { padding: 6px 16px; border: 0; border-radius: 4px; font: 600 12px/1 inherit; text-transform: uppercase; text-decoration: none; cursor: pointer; }
        .toolbar .primary { background: #ea580c; color: #fff; }
        .toolbar .secondary { background: #fff; color: #1b1b18; }
        h1, h2, h3, p { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th { font-weight: 400; text-align: left; }
        .muted { color: #777; }
        .small { font-size: 11px; }
        .bold { font-weight: 700; }
        .right { text-align: right; }
        .center { text-align: center; }
        .nowrap { white-space: nowrap; }
        .dashed { border-bottom: 1px dashed #bbb; }
        .rich p { margin: 0 0 4px; }
        .rich ul, .rich ol { margin: 0 0 4px; padding-left: 18px; }
        /* Drawn over the content (faint, so text stays readable) so grey table rows can't hide it; sized to fit the page diagonally. */
        .watermark { position: absolute; top: 50%; left: 50%; z-index: 2; transform: translate(-50%, -50%) rotate(-35deg); color: rgba(0, 0, 0, .09); font-size: 58px; font-weight: 800; letter-spacing: 2px; line-height: 1; white-space: nowrap; pointer-events: none; user-select: none; }
        .content { position: relative; z-index: 1; }
        .avoid-break { break-inside: avoid; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; overflow: visible; }
            /* Padding and border live on the page itself, so they stay even when the print dialog's margins are set to None. */
            .frame { min-height: 0; margin: 4mm; padding: 8mm 9mm; -webkit-box-decoration-break: clone; box-decoration-break: clone; }
            .watermark { position: fixed; }
        }
        @yield('styles')
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="primary" onclick="window.print()">Print</button>
        @if ($pdfUrl)
            <a class="primary" href="{{ $pdfUrl }}">Download PDF</a>
        @endif
        <button type="button" class="secondary" onclick="window.close()">Close</button>
    </div>

    <div class="sheet">
        @if ($watermark)
            <div class="watermark">{{ $watermark }}</div>
        @endif

        <div class="frame">
            <div class="content">
                @yield('content')
            </div>
        </div>
    </div>

    @if ($autoPrint)
        <script>
            window.addEventListener('load', () => setTimeout(() => window.print(), 300));
        </script>
    @endif
</body>
</html>
