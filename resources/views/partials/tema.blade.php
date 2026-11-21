{{--
    Tema terang/gelap. Dipasang di <head> SEBELUM stylesheet supaya halaman
    langsung tampil dengan tema yang benar (tidak berkedip gelap dulu).
    Bawaan: light. Pilihan tiap pengguna disimpan di localStorage browsernya.
--}}
<script>
    (function () {
        var tema = 'light';
        try {
            var simpanan = localStorage.getItem('tema');
            if (simpanan === 'dark' || simpanan === 'light') tema = simpanan;
        } catch (e) {}
        document.documentElement.setAttribute('data-theme', tema);
        // Warna bilah browser di HP (meta theme-color sudah ada di atas partial ini)
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', tema === 'light' ? '#f5f6f8' : '#0b0e13');
    })();

    /** Warna lapisan tipis sesuai tema, untuk Chart.js & style dari JS. */
    function warnaTema(alpha) {
        var rgb = getComputedStyle(document.documentElement).getPropertyValue('--tint-rgb').trim() || '255, 255, 255';
        return 'rgba(' + rgb + ', ' + alpha + ')';
    }

    /** Warna teks redup sesuai tema (label grafik). */
    function warnaTeksRedup() {
        return getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() || '#9ca3af';
    }

    function gantiTema() {
        var baru = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', baru);
        try { localStorage.setItem('tema', baru); } catch (e) {}
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', baru === 'light' ? '#f5f6f8' : '#0b0e13');
        // Grafik digambar di canvas dengan warna saat dibuat; muat ulang agar ikut tema
        if (window.Chart && Chart.instances && Object.keys(Chart.instances).length) location.reload();
    }
</script>
