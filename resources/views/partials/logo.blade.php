{{-- Logo Tradingku: tiga candlestick naik di atas kotak emas. $size = lebar px (default 36). --}}
@php
    $size = $size ?? 36;
    $gid = "tkGold" . uniqid();
@endphp
<svg class="brand-mark" width="{{ $size }}" height="{{ $size }}" viewBox="0 0 32 32" aria-hidden="true">
    <defs>
        <linearGradient id="{{ $gid }}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#f3d995"/>
            <stop offset=".5" stop-color="#d4a64a"/>
            <stop offset="1" stop-color="#a87b1f"/>
        </linearGradient>
    </defs>
    <rect width="32" height="32" rx="8" fill="url(#{{ $gid }})"/>
    <g fill="#17120a">
        <rect x="8.25" y="15" width="1.5" height="10" rx=".75"/>
        <rect x="7" y="17.5" width="4" height="5.5" rx="1"/>
        <rect x="15.25" y="10" width="1.5" height="12" rx=".75"/>
        <rect x="14" y="12" width="4" height="7.5" rx="1"/>
        <rect x="22.25" y="5" width="1.5" height="12" rx=".75"/>
        <rect x="21" y="7" width="4" height="7.5" rx="1"/>
    </g>
</svg>
