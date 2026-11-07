<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { size: A4 landscape; margin: 6mm; }
        * { box-sizing: border-box; }
        body { margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; color: #222; font: 8px/1.3 Arial, "Segoe UI", sans-serif; }
        h1 { margin: 0 0 6px; font-size: 13px; font-weight: 400; }
        h2 { margin: 0 0 4px; font-size: 10px; }
        .section { margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th, td { padding: 2px 3px; border: 1px solid #c8c8c8; text-align: center; vertical-align: middle; word-break: break-word; white-space: pre-line; }
        th { color: #0000ff; font-weight: 700; }
        td.empty { color: #706f6c; }
        tr { break-inside: avoid; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>

    @foreach ($sheets as $sheet)
        <div class="section">
            <h2>{{ $sheet['title'] }}</h2>
            <table>
                <thead>
                    <tr>
                        @foreach ($sheet['headers'] as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sheet['rows'] as $cells)
                        <tr>
                            @foreach ($cells as $cell)
                                <td>{{ is_float($cell) ? number_format($cell, 2) : $cell }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td class="empty" colspan="{{ count($sheet['headers']) }}">No {{ strtolower($sheet['title']) }} on this date.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endforeach
</body>
</html>
