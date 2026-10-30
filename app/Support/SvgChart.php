<?php

namespace App\Support;

/**
 * Grafik SVG statis yang dirender di server.
 *
 * Dipakai oleh halaman web dan PDF (dompdf tidak menjalankan JavaScript), jadi
 * hanya memakai elemen SVG dasar: polygon, line, circle, polyline, text.
 */
class SvgChart
{
    public const PALETTE = ['#2563eb', '#16a34a', '#f59e0b', '#db2777', '#7c3aed', '#0891b2', '#ea580c', '#65a30d', '#64748b', '#be123c'];

    /**
     * Pie chart. Warna per irisan diambil dari 'color' atau palet berurutan.
     *
     * @param array<int, array{value: float, color?: string}> $slices
     */
    public static function pie(array $slices, int $size = 200): string
    {
        $r = $size / 2 - 2;
        $c = $size / 2;
        $total = array_sum(array_map(fn ($s) => max(0, $s['value']), $slices));

        $body = '';
        if ($total > 0) {
            $angle = -M_PI / 2; // mulai dari jam 12
            foreach (array_values($slices) as $i => $s) {
                $value = max(0, $s['value']);
                if ($value <= 0) {
                    continue;
                }
                $color = $s['color'] ?? self::color($i);
                $sweep = $value / $total * 2 * M_PI;

                // Satu irisan penuh tidak bisa digambar sebagai arc (titik awal = titik akhir).
                if ($sweep >= 2 * M_PI - 1e-6) {
                    $body .= sprintf('<circle cx="%.2f" cy="%.2f" r="%.2f" fill="%s"/>', $c, $c, $r, $color);
                    break;
                }

                // Busur digambar sebagai poligon rapat: perintah arc (A) pada SVG
                // kadang tidak tergambar oleh dompdf.
                $points = [sprintf('%.2f,%.2f', $c, $c)];
                $steps = max(2, (int)ceil($sweep / (M_PI / 90)));
                for ($k = 0; $k <= $steps; $k++) {
                    $a = $angle + $sweep * $k / $steps;
                    $points[] = sprintf('%.2f,%.2f', $c + $r * cos($a), $c + $r * sin($a));
                }
                $angle += $sweep;

                $body .= sprintf(
                    '<polygon points="%s" fill="%s" stroke="#ffffff" stroke-width="1.5"/>',
                    implode(' ', $points), $color
                );
            }
        }

        return self::wrap($size, $size, $body);
    }

    /**
     * Grafik garis beberapa seri dengan label sumbu X yang sama.
     *
     * @param array<int, string> $labels
     * @param array<int, array{values: array<int, float>, color: string, dashed?: bool}> $series
     * @param callable(float): string $formatY
     */
    public static function line(array $labels, array $series, callable $formatY, int $width = 640, int $height = 260): string
    {
        $padL = 78; $padR = 16; $padT = 14; $padB = 34;
        $plotW = $width - $padL - $padR;
        $plotH = $height - $padT - $padB;

        $all = array_merge(...array_map(fn ($s) => $s['values'], $series));
        $min = min($all);
        $max = max($all);
        // Beri ruang di atas & bawah supaya garis tidak menempel tepi.
        $span = max($max - $min, abs($max) * 0.05, 1);
        $min -= $span * 0.15;
        $max += $span * 0.15;

        $n = count($labels);
        $x = fn ($i) => $padL + ($n > 1 ? $i / ($n - 1) * $plotW : $plotW / 2);
        $y = fn ($v) => $padT + (1 - ($v - $min) / ($max - $min)) * $plotH;

        $body = '';
        for ($g = 0; $g <= 4; $g++) {
            $v = $min + ($max - $min) * $g / 4;
            $gy = $y($v);
            $body .= sprintf('<line x1="%d" y1="%.2f" x2="%d" y2="%.2f" stroke="#e5e7eb" stroke-width="1"/>', $padL, $gy, $width - $padR, $gy);
            $body .= sprintf('<text x="%d" y="%.2f" font-size="10" fill="#6b7280" text-anchor="end">%s</text>', $padL - 6, $gy + 3.5, e($formatY($v)));
        }

        // Label X: kalau terlalu banyak titik, tampilkan berselang.
        $step = max(1, (int)ceil($n / 7));
        foreach ($labels as $i => $label) {
            if ($i % $step === 0 || $i === $n - 1) {
                $body .= sprintf('<text x="%.2f" y="%d" font-size="10" fill="#6b7280" text-anchor="middle">%s</text>', $x($i), $height - 12, e($label));
            }
        }

        foreach ($series as $s) {
            $points = [];
            foreach (array_values($s['values']) as $i => $v) {
                $points[] = sprintf('%.2f,%.2f', $x($i), $y($v));
            }
            $dash = !empty($s['dashed']) ? ' stroke-dasharray="6,4"' : '';
            $body .= sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="2.5"%s/>', implode(' ', $points), $s['color'], $dash);
            foreach (array_values($s['values']) as $i => $v) {
                $body .= sprintf('<circle cx="%.2f" cy="%.2f" r="3.5" fill="%s"/>', $x($i), $y($v), $s['color']);
            }
        }

        return self::wrap($width, $height, $body);
    }

    public static function color(int $i): string
    {
        return self::PALETTE[$i % count(self::PALETTE)];
    }

    /** Data URI, bisa langsung dipakai sebagai src <img> di web maupun dompdf. */
    public static function dataUri(string $svg): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    protected static function wrap(int $w, int $h, string $body): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" font-family="DejaVu Sans, Arial, sans-serif">%s</svg>',
            $w, $h, $w, $h, $body
        );
    }
}
