<!-- OptiLifeSync - PWA Mobil Uygulama Meta Etiketleri -->
<link rel="manifest" href="manifest.json">
<meta name="theme-color" content="#080f1e">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="OptiLifeSync">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
<link rel="icon" type="image/png" sizes="512x512" href="assets/icons/icon-512.png">

<script>
    // Hem localhost/Gyp/ hem de Vercel/kök dizin uyumlu evrensel API yolu
    window.API_BASE = (function() {
        var p = (window.location.pathname || '').toLowerCase();
        if (p.indexOf('/gyp') !== -1) {
            return '/Gyp/api';
        }
        return '/api';
    })();
</script>
