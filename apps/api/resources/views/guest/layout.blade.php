<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>@yield('title', 'Guest stay') · Inn</title>
    <style>
        :root { color-scheme: light; --ink:#18211b; --muted:#647067; --line:#dce3dd; --brand:#246b47; --brand-dark:#174b32; --paper:#fff; --wash:#f3f6f3; --danger:#a32929; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--wash); color:var(--ink); font:16px/1.55 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        a { color:var(--brand); }
        .shell { width:min(1060px,calc(100% - 32px)); margin:0 auto; }
        header { background:var(--ink); color:#fff; }
        .topbar { display:flex; align-items:center; justify-content:space-between; gap:20px; min-height:70px; }
        .brand { color:#fff; text-decoration:none; font-weight:800; letter-spacing:.02em; }
        nav { display:flex; gap:6px; flex-wrap:wrap; padding:12px 0; }
        nav a { color:#dbe8df; padding:8px 11px; border-radius:8px; text-decoration:none; font-size:14px; }
        nav a[aria-current="page"], nav a:hover { background:#30433a; color:#fff; }
        main { padding:34px 0 56px; }
        h1 { margin:0 0 8px; font-size:clamp(28px,5vw,44px); line-height:1.08; }
        h2 { margin:0 0 14px; font-size:22px; }
        .lede,.muted { color:var(--muted); }
        .grid { display:grid; gap:18px; }
        .grid.two { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .grid.three { grid-template-columns:repeat(3,minmax(0,1fr)); }
        .card { background:var(--paper); border:1px solid var(--line); border-radius:16px; padding:22px; box-shadow:0 8px 22px rgba(24,33,27,.04); }
        .stack > * + * { margin-top:18px; }
        .stat { font-size:27px; font-weight:800; }
        .pill { display:inline-flex; padding:4px 9px; border-radius:999px; background:#e6f2ea; color:var(--brand-dark); font-size:13px; font-weight:700; }
        .row { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; padding:14px 0; border-bottom:1px solid var(--line); }
        .row:last-child { border-bottom:0; }
        label { display:block; font-weight:700; font-size:14px; }
        input,select,textarea { width:100%; margin-top:6px; border:1px solid #bfc9c1; border-radius:9px; background:#fff; padding:11px 12px; color:var(--ink); font:inherit; }
        input[type="checkbox"] { width:auto; margin:0 8px 0 0; }
        textarea { min-height:110px; resize:vertical; }
        button,.button { display:inline-flex; align-items:center; justify-content:center; border:0; border-radius:9px; background:var(--brand); color:#fff; padding:11px 16px; font:700 15px inherit; text-decoration:none; cursor:pointer; }
        button:hover,.button:hover { background:var(--brand-dark); }
        .button.secondary { background:#e7ece8; color:var(--ink); }
        .notice { margin-bottom:20px; border-radius:10px; padding:13px 15px; background:#e3f3e8; color:#184a30; }
        .notice.error { background:#f9e5e5; color:var(--danger); }
        table { width:100%; border-collapse:collapse; }
        th,td { padding:11px 9px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        th { color:var(--muted); font-size:13px; text-transform:uppercase; letter-spacing:.04em; }
        .amount { text-align:right; white-space:nowrap; }
        .logout { display:inline; }
        .logout button { background:transparent; color:#dbe8df; padding:8px 11px; font-weight:500; }
        @media (max-width:760px) { .grid.two,.grid.three { grid-template-columns:1fr; } .topbar { align-items:flex-start; flex-direction:column; padding:18px 0 8px; } nav { overflow-x:auto; flex-wrap:nowrap; width:100%; } .row { flex-direction:column; gap:5px; } }
    </style>
</head>
<body>
<header>
    <div class="shell">
        <div class="topbar">
            <a class="brand" href="{{ route('guest.portal.home') }}">Inn · Guest stay</a>
            <nav aria-label="Guest portal">
                <a href="{{ route('guest.portal.home') }}" @if(request()->routeIs('guest.portal.home')) aria-current="page" @endif>Stay</a>
                <a href="{{ route('guest.portal.pre-arrival') }}" @if(request()->routeIs('guest.portal.pre-arrival*')) aria-current="page" @endif>Pre-arrival</a>
                <a href="{{ route('guest.portal.documents') }}" @if(request()->routeIs('guest.portal.documents*')) aria-current="page" @endif>Documents</a>
                <a href="{{ route('guest.portal.payments') }}" @if(request()->routeIs('guest.portal.payments*')) aria-current="page" @endif>Payment</a>
                <a href="{{ route('guest.portal.folio') }}" @if(request()->routeIs('guest.portal.folio')) aria-current="page" @endif>Folio</a>
                <a href="{{ route('guest.portal.survey') }}" @if(request()->routeIs('guest.portal.survey*')) aria-current="page" @endif>Feedback</a>
                <a href="{{ route('guest.portal.communication-preferences') }}" @if(request()->routeIs('guest.portal.communication-preferences*')) aria-current="page" @endif>Messages</a>
                <form class="logout" method="post" action="{{ route('guest.portal.logout') }}">@csrf<button type="submit">Sign out</button></form>
            </nav>
        </div>
    </div>
</header>
<main class="shell">
    @if (session('success'))<div class="notice">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="notice error">{{ session('error') }}</div>@endif
    @if ($errors->any())<div class="notice error"><strong>Please fix the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
</body>
</html>
