<?php
// Cookie consent banner — include after <body> on all pages.
// Reads/writes af_consent cookie (365 days).
// On accept: loads GA4 directly via gtag.js (no GTM).
// On decline: no analytics loaded.
// GA4 Measurement ID: G-QMPMDVQJGP
?>
<div id="af-consent-banner" role="dialog" aria-label="Cookie consent" aria-live="polite" style="display:none">
    <div class="consent-inner">
        <p class="consent-text">We use analytics cookies to understand how visitors use our site (<a href="/cookies.php">Cookie Policy</a>). Only activated with your consent.</p>
        <div class="consent-actions">
            <button id="af-consent-accept" class="consent-btn consent-btn-accept">Accept</button>
            <button id="af-consent-decline" class="consent-btn consent-btn-decline">Decline</button>
        </div>
    </div>
</div>

<style>
#af-consent-banner {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #1a2744;
    color: #fff;
    padding: 1rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    box-shadow: 0 -2px 12px rgba(0,0,0,0.25);
}
.consent-inner {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    max-width: 900px;
    width: 100%;
    flex-wrap: wrap;
}
.consent-text {
    margin: 0;
    font-size: 0.875rem;
    line-height: 1.5;
    flex: 1;
    min-width: 200px;
}
.consent-text a { color: #93c5fd; }
.consent-actions { display: flex; gap: 0.75rem; flex-shrink: 0; }
.consent-btn {
    padding: 8px 20px;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    white-space: nowrap;
}
.consent-btn-accept { background: #f59e0b; color: #1a2744; }
.consent-btn-accept:hover { background: #d97706; }
.consent-btn-decline { background: transparent; color: #cbd5e1; border: 1px solid rgba(255,255,255,0.3); }
.consent-btn-decline:hover { color: #fff; border-color: rgba(255,255,255,0.6); }
</style>

<script>
(function() {
    var GA4_ID = 'G-QMPMDVQJGP';

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function setCookie(name, value, days) {
        var expires = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax';
    }

    function loadGA4() {
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + GA4_ID;
        document.head.appendChild(s);
        window.dataLayer = window.dataLayer || [];
        window.gtag = function(){ dataLayer.push(arguments); };
        gtag('js', new Date());
        gtag('config', GA4_ID, { 'anonymize_ip': true });
    }

    function hideBanner() {
        var banner = document.getElementById('af-consent-banner');
        if (banner) banner.style.display = 'none';
    }

    // Expose consent state globally so scanner.js can read it
    var consent = getCookie('af_consent');
    window.__af_analytics_consent = consent;

    if (consent === 'accepted') {
        loadGA4();
    } else if (consent !== 'declined') {
        // No choice yet — show banner
        document.getElementById('af-consent-banner').style.display = 'flex';

        document.getElementById('af-consent-accept').addEventListener('click', function() {
            setCookie('af_consent', 'accepted', 365);
            window.__af_analytics_consent = 'accepted';
            loadGA4();
            hideBanner();
        });

        document.getElementById('af-consent-decline').addEventListener('click', function() {
            setCookie('af_consent', 'declined', 365);
            window.__af_analytics_consent = 'declined';
            hideBanner();
        });
    }
})();
</script>
