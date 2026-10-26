<?php
/** @var array $_ */
\OCP\Util::addScript('starrate', 'starrate-guest');
\OCP\Util::addStyle('starrate', 'starrate-guest');

$token        = $_['token']         ?? '';
$canRate      = $_['can_rate']      ?? false;
$allowRating  = $_['allow_rating']  ?? true;
$allowColor   = $_['allow_color']   ?? true;
$allowPick    = $_['allow_pick']    ?? false;
$allowExport  = $_['allow_export']  ?? false;
$allowComment = $_['allow_comment'] ?? false;
$allowDownload = $_['allow_download'] ?? false;
$guestName    = $_['guest_name']    ?? '';

// Server-URL für den App Deep Link (starrate://guest?token=...&server=...)
$serverUrl = \OCP\Server::get(\OCP\IURLGenerator::class)->getAbsoluteURL('/');
$serverUrl = rtrim($serverUrl, '/');
?>
<div
  id="starrate-guest-root"
  data-token="<?= htmlspecialchars($token, ENT_QUOTES) ?>"
  data-can-rate="<?= $canRate ? 'true' : 'false' ?>"
  data-allow-rating="<?= $allowRating ? 'true' : 'false' ?>"
  data-allow-color="<?= $allowColor ? 'true' : 'false' ?>"
  data-allow-pick="<?= $allowPick ? 'true' : 'false' ?>"
  data-allow-export="<?= $allowExport ? 'true' : 'false' ?>"
  data-allow-comment="<?= $allowComment ? 'true' : 'false' ?>"
  data-allow-download="<?= $allowDownload ? 'true' : 'false' ?>"
  data-guest-name="<?= htmlspecialchars($guestName, ENT_QUOTES) ?>"
></div>

<?php
// ─── Mobile App Banner ───────────────────────────────────────────────────
// Auf Mobilgeräten (aber NICHT im StarRate-WebView): Banner unten am Screen.
// Nutzt starrate:// Custom Scheme → App öffnen; Fallback → Play Store.
//
// Admin-Schalter: occ config:app:set starrate show_app_banner --value=yes
$showAppBanner = ($_['show_app_banner'] ?? false);

$appUrl = 'starrate://guest?token=' . urlencode($token)
        . '&server=' . urlencode($serverUrl);
?>
<!-- ─── Auto-Redirect: App öffnen wenn installiert (immer aktiv) ──────── -->
<script nonce="<?= \OCP\Server::get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)->getNonce() ?>">
(function() {
    var ua = navigator.userAgent;
    // Nur Android-Mobile, nicht im eigenen WebView
    if (!/Android/i.test(ua) || ua.indexOf('StarRateApp') !== -1) return;
    // Wenn URL bereits ?noapp=1 hat, war der Fallback → nicht nochmal versuchen
    if (/[?&]noapp=1/.test(location.search)) return;

    // intent:// mit Fallback-URL = aktuelle Seite + noapp=1
    var fallback = location.href + (location.search ? '&' : '?') + 'noapp=1';
    var intentUrl = 'intent://guest?token=<?= urlencode($token) ?>'
        + '&server=<?= urlencode($serverUrl) ?>'
        + '#Intent;scheme=starrate;S.browser_fallback_url=' + encodeURIComponent(fallback) + ';end';
    window.location.href = intentUrl;
})();
</script>

<?php if ($showAppBanner): ?>
<!-- ─── Sichtbarer Banner mit Store-Fallback (Admin-Schalter) ────────── -->
<div id="sr-app-banner"
   style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:2147483647;
    background:#0082c9;color:#fff !important;padding:14px 20px;font-size:16px;
    font-weight:500;text-align:center;text-decoration:none;cursor:pointer;
    box-shadow:0 -2px 8px rgba(0,0,0,.4);
    -webkit-tap-highlight-color:rgba(255,255,255,.3);
    transition:transform .3s ease,opacity .3s ease">
    ⭐ In StarRate App öffnen
</div>
<script nonce="<?= \OCP\Server::get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)->getNonce() ?>">
(function() {
    var ua = navigator.userAgent;
    if (!/Android|iPhone|iPad/i.test(ua) || ua.indexOf('StarRateApp') !== -1) return;

    var banner = document.getElementById('sr-app-banner');
    banner.style.display = 'block';

    var appUrl = <?= json_encode($appUrl) ?>;
    var storeUrl = 'https://play.google.com/store/apps/details?id=de.merlin1.starrate';

    // Klick: App öffnen, Fallback → Store
    banner.addEventListener('click', function() {
        var t0 = Date.now();
        window.location.href = appUrl;
        setTimeout(function() {
            if (document.hidden || Date.now() - t0 > 2000) return;
            window.location.href = storeUrl;
        }, 1500);
    });

    // Banner ausblenden sobald User scrollt (will offenbar im Browser bleiben)
    var hidden = false;
    function hideBanner() {
        if (hidden) return;
        hidden = true;
        banner.style.transform = 'translateY(100%)';
        banner.style.opacity = '0';
        setTimeout(function() { banner.style.display = 'none'; }, 350);
    }
    var grid = document.querySelector('.sr-grid');
    if (grid) grid.addEventListener('scroll', hideBanner, {passive: true, once: true});
    document.addEventListener('scroll', hideBanner, {passive: true, capture: true, once: true});
})();
</script>
<?php endif; ?>
