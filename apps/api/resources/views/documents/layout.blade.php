<!doctype html>
<html lang="{{ $snapshot['locale'] }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28mm 18mm 22mm; }
        body { color: #17211b; font-family: DejaVu Sans, sans-serif; font-size: 10pt; line-height: 1.45; }
        h1 { color: #173e2c; font-size: 22pt; margin: 0 0 4mm; }
        h2 { border-bottom: 1px solid #ccd8d0; color: #295943; font-size: 13pt; margin-top: 7mm; padding-bottom: 2mm; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #e2e8e4; padding: 2.2mm 1.5mm; text-align: left; vertical-align: top; }
        th { background: #f2f6f3; }
        .muted { color: #607067; }
        .amount { text-align: right; white-space: nowrap; }
        footer { bottom: -13mm; color: #6d786f; font-size: 8pt; position: fixed; text-align: center; width: 100%; }
    </style>
</head>
<body>
    <footer>{{ $snapshot['payload']['property']['name'] }} · {{ str_replace('_', ' ', ucfirst($snapshot['kind'])) }}</footer>
    @yield('content')
</body>
</html>
