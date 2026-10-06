<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { size: A3 landscape; margin: 6mm; }
        * { box-sizing: border-box; }
        body { margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; color: #222; font: 7px/1.3 Arial, "Segoe UI", sans-serif; }
        h1 { margin: 0 0 6px; font-size: 13px; font-weight: 400; }
        .section { margin-bottom: 12px; }
        .summary { padding: 4px 6px; background: #48ca10; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; table-layout: auto; }
        thead { display: table-header-group; }
        th, td { padding: 2px 3px; border: 1px solid #c8c8c8; text-align: center; vertical-align: middle; word-break: break-word; }
        th { color: #0000ff; font-weight: 700; }
        td.group { background: #e3e3e3; text-align: left; font-weight: 700; }
        td.pre { white-space: pre-line; }
        tr { break-inside: avoid; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>

    @foreach ($sections as $section)
        <div class="section">
            <div class="summary" style="background: #{{ $section['color'] }}">{{ $section['summary'] }}</div>

            @if ($section['groups'] !== [])
                <table>
                    <thead>
                        <tr>
                            @foreach ($headers as $header)
                                <th>{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($section['groups'] as $source => $rows)
                            @if ($source !== '')
                                <tr><td class="group" colspan="{{ count($headers) }}">{{ $source }}</td></tr>
                            @endif
                            @foreach ($rows as $cells)
                                <tr>
                                    @foreach ($cells as $cell)
                                        <td class="pre">{{ is_float($cell) ? number_format($cell, 2) : $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endforeach
</body>
</html>
