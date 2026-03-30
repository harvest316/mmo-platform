/**
 * Audit&Fix Landing Page JavaScript
 *
 * Handles: currency switching, form validation, PayPal Smart Payment Buttons,
 *          first-visit discount countdown
 */

/* global paypal, window, document */

(function () {
  'use strict';

  // ── Capture gclid from URL and persist in sessionStorage ────────────────
  // gclid is appended by Google Ads to ad click URLs (?gclid=XXXX).
  // We store it in sessionStorage so it survives the PayPal redirect flow.
  (function captureGclid() {
    const gclid = new URLSearchParams(window.location.search).get('gclid');
    if (gclid) sessionStorage.setItem('af_gclid', gclid);
  })();

  function getGclid() {
    return sessionStorage.getItem('af_gclid') || null;
  }

  // ── First-visit discount countdown ──────────────────────────────────────
  const DEAL_DISCOUNT = window.DEAL_DISCOUNT || 0.2;
  const DEAL_DURATION_MS = window.DEAL_DURATION_MS || 20 * 60 * 1000;
  let dealActive = false;

  function initDealCountdown() {
    const banner = document.getElementById('deal-banner');
    if (!banner) return;

    // Expiry timestamp is set server-side via PHP session (window.DEAL_EXPIRES_AT)
    const expiresAt = window.DEAL_EXPIRES_AT || 0;
    if (!expiresAt || expiresAt <= Date.now()) {
      banner.style.display = 'none';
      const orderBanner = document.getElementById('order-deal-banner');
      if (orderBanner) orderBanner.style.display = 'none';
      return;
    }

    function tick() {
      const remaining = expiresAt - Date.now();
      if (remaining <= 0) {
        dealActive = false;
        banner.style.display = 'none';
        const orderBanner = document.getElementById('order-deal-banner');
        if (orderBanner) orderBanner.style.display = 'none';
        updatePriceDisplay();
        return;
      }

      dealActive = true;
      banner.style.display = 'block';

      const totalSecs = Math.ceil(remaining / 1000);
      const mins = String(Math.floor(totalSecs / 60)).padStart(2, '0');
      const secs = String(totalSecs % 60).padStart(2, '0');
      const timeStr = `${mins}:${secs}`;

      // Update all timer elements (sticky banner + order form)
      document.querySelectorAll('#deal-timer, #order-deal-timer').forEach(el => {
        el.textContent = timeStr;
      });

      // Show order deal banner
      const orderBanner = document.getElementById('order-deal-banner');
      if (orderBanner) orderBanner.style.display = 'block';

      updatePriceDisplay();
      setTimeout(tick, 1000);
    }

    tick();
  }

  // ── Pricing ─────────────────────────────────────────────────────────────

  const pricing = window.PRICING_DATA || {};
  const detectedCountry = window.DETECTED_COUNTRY || 'US';
  let currentPrice = window.INITIAL_PRICE || {
    price: 29700,
    currency: 'USD',
    symbol: '$',
    formatted: '$297',
  };

  // Populate country/currency dropdown
  const currencySelect = document.getElementById('currency');
  if (currencySelect && Object.keys(pricing).length > 0) {
    // Build per-country list (skip _meta key)
    const countries = [];
    for (const [code, data] of Object.entries(pricing)) {
      if (code === '_meta' || !data.currency) continue;
      countries.push({
        code,
        currency: data.currency,
        symbol: data.symbol,
        name: data.country_name || code,
      });
    }

    // Sort: detected country first, then alphabetically by country name
    countries.sort((a, b) => {
      if (a.code === detectedCountry) return -1;
      if (b.code === detectedCountry) return 1;
      return a.name.localeCompare(b.name);
    });

    for (const c of countries) {
      const option = document.createElement('option');
      option.value = c.code;
      option.textContent = `${c.name} — ${c.currency} (${c.symbol})`;
      if (c.code === detectedCountry) option.selected = true;
      currencySelect.appendChild(option);
    }

    currencySelect.addEventListener('change', function () {
      const selected = pricing[this.value];
      if (selected) {
        currentPrice = selected;
        updatePriceDisplay();
      }
    });
  }

  function getEffectivePrice() {
    // Test price override: ?test_price=1.00 bypasses normal pricing (no discount applied)
    if (window.TEST_PRICE !== null && window.TEST_PRICE !== undefined) {
      const cents = Math.round(window.TEST_PRICE * 100);
      return {
        ...currentPrice,
        price: cents,
        formatted: currentPrice.symbol + window.TEST_PRICE.toFixed(2),
      };
    }
    if (!dealActive) return currentPrice;
    const discounted = Math.round(currentPrice.price * (1 - DEAL_DISCOUNT));
    const formatted = currentPrice.symbol + Math.round(discounted / 100);
    return { ...currentPrice, price: discounted, formatted };
  }

  function updatePriceDisplay() {
    const effective = getEffectivePrice();
    const els = document.querySelectorAll('#display-price, #hero-price');
    els.forEach(el => {
      if (dealActive) {
        el.innerHTML = `<span class="price-original">${
          currentPrice.formatted
        }</span> <span class="price-discounted">${effective.formatted}</span>`;
      } else {
        el.textContent = currentPrice.formatted;
      }
    });
  }

  // Form validation
  function validateForm() {
    const email = document.getElementById('email').value.trim();
    const urlInput = document.getElementById('url');
    let url = urlInput.value.trim();

    // Auto-prepend https:// if missing
    if (url && !/^https?:\/\//i.test(url)) {
      url = `https://${url}`;
      urlInput.value = url;
    }

    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showError('Please enter a valid email address.');
      return false;
    }

    if (!url || !/^https?:\/\/.+/.test(url)) {
      showError('Please enter a valid website URL (starting with http:// or https://).');
      return false;
    }

    hideError();
    return true;
  }

  function showError(msg) {
    let errorEl = document.querySelector('.form-error');
    if (!errorEl) {
      errorEl = document.createElement('div');
      errorEl.className = 'form-error';
      errorEl.setAttribute('role', 'alert');
      const form = document.getElementById('audit-form');
      form.insertBefore(errorEl, form.firstChild);
    }
    errorEl.textContent = msg;
    errorEl.style.display = 'block';
  }

  function hideError() {
    const errorEl = document.querySelector('.form-error');
    if (errorEl) errorEl.style.display = 'none';
  }

  function getFormData() {
    const effective = getEffectivePrice();
    return {
      email: document.getElementById('email').value.trim(),
      url: document.getElementById('url').value.trim(),
      phone: document.getElementById('phone')?.value.trim() || null,
      currency: effective.currency,
      country_code: currencySelect?.value || detectedCountry,
      amount: effective.price,
      amount_usd: dealActive
        ? Math.round((pricing.US?.price || currentPrice.price) * (1 - DEAL_DISCOUNT))
        : pricing.US?.price || currentPrice.price,
      product: window.PRODUCT || 'full_audit',
      conversation_id: window.CONVERSATION_ID || null,
      lang: window.LANG || 'en',
      sandbox: window.SANDBOX_MODE || false,
      test_price: window.TEST_PRICE ?? null,
      gclid: getGclid(),
    };
  }

  // PayPal Smart Payment Buttons
  function initPayPalButtons() {
    if (typeof paypal === 'undefined') return;
    paypal
      .Buttons({
        style: {
          layout: 'vertical',
          color: 'blue',
          shape: 'rect',
          label: 'pay',
        },

        createOrder() {
          if (!validateForm()) {
            return Promise.reject(new Error('Validation failed'));
          }

          const formData = getFormData();

          return fetch('api.php?action=create-order', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData),
          })
            .then(res => {
              return res.json();
            })
            .then(data => {
              if (data.error) throw new Error(data.error);
              return data.id;
            });
        },

        onApprove(data) {
          const formData = getFormData();
          // Stable dedup ID shared between browser pixel and server CAPI
          const eventId = 'purchase_' + Date.now() + '_' + Math.random().toString(36).slice(2);

          return fetch('api.php?action=capture-payment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              order_id: data.orderID,
              event_id: eventId,
              ...formData,
            }),
          })
            .then(res => {
              return res.json();
            })
            .then(result => {
              if (result.success) {
                // Browser-side Meta Pixel Purchase (dedup via eventId with CAPI)
                if (window.__af_pixel_loaded && typeof fbq === 'function') {
                  fbq('track', 'Purchase', {
                    value: window.INITIAL_PRICE ? window.INITIAL_PRICE.amount / 100 : 0,
                    currency: window.PAYPAL_CURRENCY || 'USD',
                    content_ids: [window.PRODUCT || 'full_audit'],
                    content_type: 'product',
                  }, { eventID: eventId });
                }
                const productParam = window.PRODUCT && window.PRODUCT !== 'full_audit' ? `&product=${window.PRODUCT}` : '';
                window.location.href = `thank-you.php?email=${encodeURIComponent(formData.email)}${productParam}`;
              } else {
                showError('Payment processing failed. Please try again.');
              }
            });
        },

        onError(err) {
          if (err.message !== 'Validation failed') {
            showError('Payment error. Please try again or contact support.');
          }
        },
      })
      .render('#paypal-button-container');
  }

  // Lazy-load PayPal SDK when the order section scrolls into view
  (function lazyLoadPayPal() {
    const target = document.getElementById('paypal-button-container');
    if (!target) return;

    function loadSdk() {
      if (document.getElementById('paypal-sdk-script')) return; // already injected
      const clientId = window.PAYPAL_CLIENT_ID || '';
      const currency =
        window.PAYPAL_CURRENCY || (window.INITIAL_PRICE && window.INITIAL_PRICE.currency) || 'USD';
      const sandbox = window.PAYPAL_SANDBOX || false;
      let src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(clientId)}&currency=${encodeURIComponent(currency)}`;
      if (sandbox) src += '&debug=true';
      const script = document.createElement('script');
      script.id = 'paypal-sdk-script';
      script.src = src;
      script.onload = initPayPalButtons;
      document.body.appendChild(script);
    }

    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver(
        function (entries, obs) {
          if (entries[0].isIntersecting) {
            obs.disconnect();
            loadSdk();
          }
        },
        { rootMargin: '200px' }
      );
      observer.observe(target);
    } else {
      // Fallback: load immediately if IntersectionObserver unsupported
      loadSdk();
    }
  })();

  // Update sites-scored counter from live data
  const sitesScored = window.SITES_SCORED;
  if (sitesScored) {
    const el = document.getElementById('sites-scored-num');
    if (el) el.textContent = `${sitesScored.toLocaleString()}+`;
  }

  // Auto-prepend https:// when user leaves the URL field
  const urlField = document.getElementById('url');
  if (urlField) {
    urlField.addEventListener('blur', function () {
      const val = this.value.trim();
      if (val && !/^https?:\/\//i.test(val)) {
        this.value = `https://${val}`;
      }
    });
  }

  // Prefill form from outreach short-URL (/o/{site_id}) params
  (function applyPrefill() {
    const domain = window.PREFILL_DOMAIN;
    const email = window.PREFILL_EMAIL;
    const country = window.PREFILL_COUNTRY;

    if (email) {
      const emailEl = document.getElementById('email');
      if (emailEl && !emailEl.value) emailEl.value = email;
    }

    if (domain) {
      const urlEl = document.getElementById('url');
      if (urlEl && !urlEl.value) {
        urlEl.value = domain.startsWith('http') ? domain : `https://${domain}`;
      }
    }

    if (country && currencySelect) {
      // Find the matching option and select it, then fire change to update price
      const opt = Array.from(currencySelect.options).find(o => o.value === country);
      if (opt) {
        currencySelect.value = country;
        currencySelect.dispatchEvent(new Event('change'));
      }
    }
  })();

  // Initialise deal countdown after DOM is ready
  initDealCountdown();
})();
