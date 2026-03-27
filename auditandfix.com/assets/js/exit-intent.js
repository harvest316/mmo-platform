/**
 * Exit-intent popup — Quick Fixes downsell ($67).
 *
 * Triggers when mouse leaves viewport (desktop only).
 * Guards: once per session, only after score shown, only if no CTA clicked.
 * Reads: window.__af_scan_domain, window.__af_cta_clicked, window.SCAN_CONFIG
 */
(function () {
  'use strict';

  // Skip on touch devices — exit-intent doesn't work
  if (!window.matchMedia('(hover: hover)').matches) return;

  var shown = false;

  function getBackdrop() {
    return document.getElementById('exit-modal-backdrop');
  }

  function showModal() {
    if (shown) return;
    if (sessionStorage.getItem('af_exit_shown')) return;
    if (!window.__af_scan_domain) return;  // no scan completed
    if (window.__af_cta_clicked) return;   // already clicked a CTA

    var backdrop = getBackdrop();
    if (!backdrop) return;

    // Set the Quick Fixes CTA link
    var cta = document.getElementById('exit-modal-cta');
    if (cta) {
      var domain = encodeURIComponent(window.__af_scan_domain);
      cta.href = '/?domain=' + domain + '&product=quick_fixes#order';
    }

    // Apply currency if scanner exposed country code
    var cfg = window.SCAN_CONFIG || {};
    var countryCode = cfg.countryCode || 'US';
    var currencyMap = { AU: 'aud', NZ: 'aud', UK: 'gbp', GB: 'gbp' };
    var key = currencyMap[countryCode] || 'usd';

    backdrop.querySelectorAll('.exit-modal-amount').forEach(function (el) {
      var val = el.getAttribute('data-' + key);
      if (val) el.textContent = val;
    });
    backdrop.querySelectorAll('.exit-modal-currency').forEach(function (el) {
      var val = el.getAttribute('data-' + key);
      if (val) el.textContent = val;
    });

    backdrop.style.display = 'flex';
    shown = true;
    sessionStorage.setItem('af_exit_shown', '1');
  }

  function hideModal() {
    var backdrop = getBackdrop();
    if (backdrop) backdrop.style.display = 'none';
  }

  // Exit intent: mouse leaves viewport upward
  document.documentElement.addEventListener('mouseleave', function (e) {
    if (e.clientY < 0) showModal();
  });

  // Close handlers
  document.addEventListener('click', function (e) {
    if (e.target.id === 'exit-modal-backdrop') hideModal();
    if (e.target.classList.contains('exit-modal-close')) hideModal();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') hideModal();
  });
})();
