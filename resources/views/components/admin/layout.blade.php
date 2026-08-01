@props(['title', 'active'])

{{--
    The admin shell.

    Plain CSS in one file rather than a build step: this app serves an API and
    has no asset pipeline running, and three screens do not justify starting
    one. The palette is the site's, so the two do not feel like different
    products when you have both open.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} — LobbyHub admin</title>
    <style>
        :root {
            --bg: #0b1220; --surface: #0f1a2b; --surface-2: #16243a;
            --line: #1f3350; --line-strong: #2b476e;
            --fg: #f1f5f9; --muted: #94a3b8; --subtle: #6b7f9a;
            --brand: #16a34a; --online: #22c55e; --offline: #64748b;
            --accent: #f59e0b; --danger: #dc2626;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--fg);
            font: 14px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        a { color: inherit; }
        header {
            display: flex; align-items: center; gap: 20px;
            padding: 14px 20px; border-bottom: 1px solid var(--line); background: var(--surface);
        }
        header b { letter-spacing: -0.02em; }
        header b span { color: var(--brand); }
        nav { display: flex; gap: 4px; }
        nav a {
            padding: 6px 12px; border-radius: 6px; color: var(--muted); text-decoration: none;
        }
        nav a:hover { background: var(--surface-2); color: var(--fg); }
        nav a[aria-current] { background: var(--surface-2); color: var(--fg); }
        main { padding: 20px; max-width: 1400px; }
        h2 {
            font-size: 12px; letter-spacing: 0.12em; text-transform: uppercase;
            color: var(--subtle); margin: 28px 0 10px;
        }
        h2:first-child { margin-top: 0; }
        .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
        .card {
            border: 1px solid var(--line); background: var(--surface);
            border-radius: 10px; padding: 14px 16px;
        }
        .card .v { font-size: 26px; font-variant-numeric: tabular-nums; letter-spacing: -0.02em; }
        .card .k { color: var(--subtle); font-size: 12px; margin-top: 2px; }
        .card .n { color: var(--muted); font-size: 12px; margin-top: 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }
        th { color: var(--subtle); font-weight: 500; font-size: 12px; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        td.wide { white-space: normal; max-width: 420px; }
        .panel { border: 1px solid var(--line); background: var(--surface); border-radius: 10px; overflow: hidden; }
        .panel table tr:last-child td { border-bottom: 0; }
        .pill {
            display: inline-block; padding: 1px 8px; border-radius: 999px;
            font-size: 12px; border: 1px solid var(--line-strong); color: var(--muted);
        }
        .pill.online { color: var(--online); border-color: color-mix(in srgb, var(--online) 40%, transparent); }
        .pill.offline { color: var(--offline); }
        .pill.unknown { color: var(--accent); border-color: color-mix(in srgb, var(--accent) 40%, transparent); }
        .muted { color: var(--muted); }
        .subtle { color: var(--subtle); }
        form.filters { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
        input, select, button {
            font: inherit; color: var(--fg); background: var(--bg);
            border: 1px solid var(--line); border-radius: 8px; padding: 7px 10px;
        }
        button { cursor: pointer; background: var(--surface-2); }
        .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; }
        @media (max-width: 900px) { .cols { grid-template-columns: 1fr; } }
        .empty { padding: 18px; color: var(--subtle); }
        .pager { display: flex; gap: 8px; margin-top: 12px; }
        .pager a, .pager span { padding: 6px 10px; border: 1px solid var(--line); border-radius: 8px; text-decoration: none; }
        .pager span { color: var(--subtle); }

        /* Editing */
        .notice {
            border: 1px solid color-mix(in srgb, var(--brand) 45%, transparent);
            background: color-mix(in srgb, var(--brand) 12%, transparent);
            border-radius: 8px; padding: 10px 14px; margin-bottom: 18px;
        }
        .notice.bad {
            border-color: color-mix(in srgb, var(--danger) 50%, transparent);
            background: color-mix(in srgb, var(--danger) 12%, transparent);
        }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 20px; }
        .fields.one { grid-template-columns: 1fr; }
        @media (max-width: 700px) { .fields { grid-template-columns: 1fr; } }
        .field > .l { display: block; color: var(--subtle); font-size: 12px; margin-bottom: 4px; }
        .field input[type="text"], .field input[type="number"], .field input[type="date"],
        .field select, .field textarea { width: 100%; }
        textarea { font: inherit; color: var(--fg); background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: 7px 10px; resize: vertical; }
        input[type="color"] { padding: 2px; height: 36px; width: 56px; }
        .hint { color: var(--subtle); font-size: 12px; margin-top: 4px; }
        .bad-field { color: var(--danger); font-size: 12px; margin-top: 4px; }
        .check { display: flex; align-items: center; gap: 8px; }
        .check input { width: auto; }
        .actions { display: flex; align-items: center; gap: 12px; margin-top: 20px; }
        button.primary { background: var(--brand); border-color: var(--brand); color: #04140a; font-weight: 600; }
        button.danger { color: var(--danger); border-color: color-mix(in srgb, var(--danger) 45%, transparent); }
        .rows { display: grid; gap: 10px; }
        .row-card { border: 1px solid var(--line); background: var(--surface); border-radius: 10px; padding: 12px 14px; }
        .row-card.gone { opacity: 0.55; }
        .row-grid { display: grid; grid-template-columns: 1fr 1fr 90px auto auto; gap: 10px 14px; align-items: end; }
        /* Versions carry a release date, so they need one column more. */
        .row-grid.dated { grid-template-columns: 1fr 1fr 90px 150px auto auto; }
        @media (max-width: 900px) { .row-grid, .row-grid.dated { grid-template-columns: 1fr 1fr; } }
        details { margin-top: 10px; }
        details summary { cursor: pointer; color: var(--subtle); font-size: 12px; }
        details > .fields { margin-top: 10px; }
        .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
        .button {
            display: inline-block; padding: 7px 12px; border-radius: 8px; text-decoration: none;
            border: 1px solid var(--line); background: var(--surface-2); color: var(--fg);
        }
    </style>
</head>
<body>
    <header>
        <b>LOBBY<span>HUB</span></b>
        <nav>
            <a href="{{ route('admin.monitoring') }}" @if($active === 'monitoring') aria-current="page" @endif>Monitoring</a>
            <a href="{{ route('admin.games') }}" @if($active === 'games') aria-current="page" @endif>Games</a>
            <a href="{{ route('admin.users') }}" @if($active === 'users') aria-current="page" @endif>Users</a>
        </nav>
    </header>
    <main>
        {{-- Said once, here, so no screen has to remember to say it. --}}
        @if(session('status'))
            <div class="notice">{{ session('status') }}</div>
        @endif

        {{-- Field-level errors are printed beside their input; this covers the
             ones that belong to the whole form, like a refused delete. --}}
        @if($errors->has('delete'))
            <div class="notice bad">{{ $errors->first('delete') }}</div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
