<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $fund_name }} - {{ $report->title() }}</title>
    <style>
        @page { margin: 2.2cm 2cm 2cm; }
        body { margin: 0; }
        .ir-footer { position: fixed; bottom: -1.2cm; left: 0; right: 0; text-align: center; font-family: 'DejaVu Sans', sans-serif; font-size: 8.5pt; color: #777; }
        .ir-footer .ir-page:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="ir-footer"><span class="ir-page"></span></div>
    @include('investor_report._document')
</body>
</html>
