<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $outlet->name }} — {{ $property->name }}</title>
    <style>
        :root { --bg: #fdfdfc; --card: #fff; --text: #1b1b18; --muted: #706f6c; --line: #e3e3e0; --accent: #f53003; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0a0a0a; --card: #161615; --text: #ededec; --muted: #a1a09a; --line: #3e3e3a; --accent: #ff4433; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 "Segoe UI", system-ui, -apple-system, Arial, sans-serif; }
        main { max-width: 640px; margin: 0 auto; padding: 24px 16px 48px; }
        header { margin-bottom: 20px; text-align: center; }
        h1 { margin: 0; font-size: 24px; }
        h2 { margin: 24px 0 8px; font-size: 13px; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
        p { margin: 4px 0 0; color: var(--muted); }
        .table { display: inline-block; margin-top: 8px; padding: 2px 10px; border-radius: 999px; background: var(--accent); color: #fff; font-weight: 600; font-size: 13px; }
        ul { margin: 0; padding: 0; list-style: none; border: 1px solid var(--line); border-radius: 10px; background: var(--card); }
        li { display: flex; justify-content: space-between; gap: 12px; padding: 12px 14px; border-top: 1px solid var(--line); }
        li:first-child { border-top: 0; }
        .price { white-space: nowrap; font-weight: 600; }
        .tax { display: block; font-size: 12px; color: var(--muted); font-weight: 400; text-align: right; }
        .empty { text-align: center; }
    </style>
</head>
<body>
    <main>
        <header>
            <h1>{{ $outlet->name }}</h1>
            <p>{{ $property->name }}</p>
            @if ($table !== '')
                <span class="table">Table {{ $table }}</span>
            @endif
        </header>

        @forelse ($categories as $category => $items)
            <h2>{{ $category }}</h2>
            <ul>
                @foreach ($items as $item)
                    <li>
                        <span>{{ $item['name'] }}</span>
                        <span class="price">
                            {{ $currency }} {{ number_format($item['price'], 2) }}
                            @if ($item['tax_rate'] > 0)
                                <span class="tax">+ {{ rtrim(rtrim(number_format($item['tax_rate'], 2), '0'), '.') }}% tax</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @empty
            <p class="empty">The menu isn't available right now.</p>
        @endforelse
    </main>
</body>
</html>
