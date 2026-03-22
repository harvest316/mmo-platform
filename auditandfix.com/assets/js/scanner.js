/**
 * Audit&Fix Free Website Scanner — Frontend JS
 *
 * Handles: URL input → scanning animation → score reveal → email capture
 * → factor breakdown → free peek → CTA cards.
 *
 * Communicates with: api.php?action=free-scan (PHP proxy → CF Worker scoring API)
 */

(function () {
  'use strict';

  const cfg = window.SCAN_CONFIG || {};

  // ── State ────────────────────────────────────────────────────────────────

  let currentScanId = null;
  let currentResult = null;

  // ── DOM refs ─────────────────────────────────────────────────────────────

  const $ = id => document.getElementById(id);

  const stageInput = $('stage-input');
  const stageScanning = $('stage-scanning');
  const stageResults = $('stage-results');

  const scanForm = $('scan-form');
  const scanUrl = $('scan-url');
  const scanBtn = $('scan-btn');
  const scanError = $('scan-error');

  const progressDomain = $('progress-domain');
  const progressSteps = [
    'step-fetch',
    'step-headline',
    'step-cta',
    'step-trust',
    'step-value',
    'step-score',
  ];

  const scoreNumber = $('score-number');
  const scoreGrade = $('score-grade');
  const gaugeFill = $('gauge-fill');
  const scoreDomainLabel = $('score-domain-label');
  const scoreContext = $('score-context');
  const issueTeaser = $('issue-teaser');
  const teaserFreePeek = $('teaser-free-peek');
  const teaserHiddenCount = $('teaser-hidden-count');

  const emailGate = $('email-gate');
  const emailForm = $('email-form');
  const emailInput = $('email-input');
  const emailBtn = $('email-btn');
  const emailError = $('email-error');

  const factorBreakdown = $('factor-breakdown');
  const factorList = $('factor-list');
  const freePeek = $('free-peek');
  const freePeekFactor = $('free-peek-factor');
  const freePeekScore = $('free-peek-score');
  const freePeekBar = $('free-peek-bar');
  const freePeekReason = $('free-peek-reasoning');
  const jsHeavyNote = $('js-heavy-note');

  const ctaFullAudit = $('cta-full-audit');
  const ctaAuditFix = $('cta-audit-fix');
  const pricingHero = $('pricing-hero');

  // ── Factor label map ─────────────────────────────────────────────────────

  const FACTOR_LABELS = {
    headline_quality: 'Headline Quality',
    value_proposition: 'Value Proposition',
    unique_selling_proposition: 'Unique Selling Point',
    call_to_action: 'Call to Action',
    urgency_messaging: 'Urgency & Scarcity',
    hook_engagement: 'Hook & Engagement',
    trust_signals: 'Trust Signals',
    imagery_design: 'Imagery & Design',
    offer_clarity: 'Offer Clarity',
    contextual_appropriateness: 'Industry Context',
  };

  const STATUS_LABELS = {
    good: 'Good',
    fair: 'Fair',
    needs_work: 'Needs Work',
  };

  // ── Helpers ───────────────────────────────────────────────────────────────

  function show(el) {
    if (el) el.style.display = '';
  }
  function hide(el) {
    if (el) el.style.display = 'none';
  }

  function showError(el, msg) {
    if (!el) return;
    el.textContent = msg;
    el.style.display = '';
  }

  function hideError(el) {
    if (!el) return;
    el.style.display = 'none';
    el.textContent = '';
  }

  function normaliseUrl(raw) {
    let url = raw.trim();
    if (!url.startsWith('http://') && !url.startsWith('https://')) {
      url = 'https://' + url;
    }
    try {
      return new URL(url).href;
    } catch {
      return null;
    }
  }

  function extractDomain(url) {
    try {
      return new URL(url).hostname.replace(/^www\./, '');
    } catch {
      return url;
    }
  }

  function gradeClass(grade) {
    if (!grade) return '';
    const g = grade[0].toLowerCase();
    if (g === 'a') return 'grade-a';
    if (g === 'b') return 'grade-b';
    if (g === 'c') return 'grade-c';
    if (g === 'd') return 'grade-d';
    return 'grade-f';
  }

  // ── Progress animation ────────────────────────────────────────────────────

  function runProgressAnimation(domain) {
    if (progressDomain) progressDomain.textContent = domain;

    const steps = progressSteps.map(id => $(id));
    let idx = 0;

    function tick() {
      if (idx > 0 && steps[idx - 1]) steps[idx - 1].className = 'progress-step done';
      if (idx < steps.length) {
        if (steps[idx]) steps[idx].className = 'progress-step active';
        idx++;
        setTimeout(tick, 900 + Math.random() * 400);
      }
    }
    tick();
  }

  // ── Score gauge animation ─────────────────────────────────────────────────

  function animateScore(targetScore, grade) {
    // SVG arc: full arc length ≈ 157 (π × r, r=50, half circle)
    const arcLen = 157;

    // Animate number counter
    let current = 0;
    const duration = 1200;
    const start = performance.now();

    function updateGauge(score) {
      if (!gaugeFill) return;
      const offset = arcLen - (arcLen * score) / 100;
      gaugeFill.style.strokeDashoffset = offset;

      // Colour: green for high, orange for mid, red for low
      const colour = score >= 83 ? '#48bb78' : score >= 70 ? '#ed8936' : '#fc8181';
      gaugeFill.style.stroke = colour;
    }

    function tick(now) {
      const elapsed = now - start;
      const progress = Math.min(elapsed / duration, 1);
      // Ease-out
      const eased = 1 - Math.pow(1 - progress, 3);
      current = Math.round(eased * targetScore);

      if (scoreNumber) scoreNumber.textContent = current;
      updateGauge(current);

      if (progress < 1) {
        requestAnimationFrame(tick);
      } else {
        if (scoreNumber) scoreNumber.textContent = targetScore;
        updateGauge(targetScore);
      }
    }

    requestAnimationFrame(tick);

    // Grade
    if (scoreGrade) {
      scoreGrade.textContent = grade;
      scoreGrade.className = 'score-grade ' + gradeClass(grade);
    }
  }

  // ── API call ──────────────────────────────────────────────────────────────

  async function callApi(action, body) {
    const res = await fetch(cfg.apiBase + '/api.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  // ── Pricing currency ─────────────────────────────────────────────────────

  function applyPricingCurrency(countryCode) {
    // Map country to currency key
    const currencyMap = { AU: 'aud', UK: 'gbp', GB: 'gbp', NZ: 'aud' };
    const key = currencyMap[countryCode] || 'usd';

    // Swap all data-* driven price elements
    document.querySelectorAll('.pricing-amount').forEach(el => {
      const val = el.getAttribute('data-' + key);
      if (val) el.textContent = val;
    });
    document.querySelectorAll('.pricing-currency').forEach(el => {
      const val = el.getAttribute('data-' + key);
      if (val) el.textContent = val;
    });
    // Exit-intent modal prices (if loaded)
    document.querySelectorAll('.exit-modal-amount').forEach(el => {
      const val = el.getAttribute('data-' + key);
      if (val) el.textContent = val;
    });
  }

  // ── Stage transitions ─────────────────────────────────────────────────────

  function showScanning(domain) {
    hide(stageInput);
    show(stageScanning);
    hide(stageResults);
    const statusEl = document.getElementById('scan-status');
    if (statusEl) statusEl.textContent = 'Scanning ' + domain + ', please wait\u2026';
    runProgressAnimation(domain);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function showResults(result) {
    hide(stageInput);
    hide(stageScanning);
    show(stageResults);

    const domain = result.domain || extractDomain(result.url || '');

    const statusEl = document.getElementById('scan-status');
    if (statusEl)
      statusEl.textContent =
        'Scan complete. ' + domain + ' scored ' + result.score + ' out of 100.';

    // Score + gauge
    animateScore(result.score, result.grade);

    if (scoreDomainLabel) scoreDomainLabel.textContent = domain;

    // Context line
    if (result.industry_percentile) {
      const pct = result.industry_percentile.percentile;
      const ind = result.industry || 'small business';
      const pctLabel = pct <= 35 ? `bottom ${pct}%` : pct >= 65 ? `top ${100 - pct}%` : `middle`;
      scoreContext.textContent = `Ranked in the ${pctLabel} of ${ind} websites`;
    }

    // Issues count + email gate copy
    const count = typeof result.issues_count === 'number' ? result.issues_count : 0;
    const emailGateTitle = document.getElementById('email-gate-title');
    const emailGateDesc = document.getElementById('email-gate-desc');
    if (count === 0) {
      if (emailGateTitle)
        emailGateTitle.textContent = 'Your site scores well — see the full breakdown';
      if (emailGateDesc)
        emailGateDesc.textContent =
          'Enter your email to unlock all 10 factor scores and see where you still have room to improve.';
    } else {
      if (emailGateTitle)
        emailGateTitle.innerHTML = `We found <strong>${count}</strong> ${count === 1 ? 'area' : 'areas'} to work on`;
      if (emailGateDesc)
        emailGateDesc.textContent = `Enter your email to unlock all ${count} ${count === 1 ? 'issue' : 'issues'} — and get a free detailed look at your biggest problem.`;
    }

    // Issue teaser — show free peek immediately (no email required)
    if (result.free_peek) {
      const peek = result.free_peek;
      const label = FACTOR_LABELS[peek.factor] || peek.factor.replace(/_/g, ' ');
      const teaserLabel = peek.score >= 7 ? 'Your lowest-scoring factor' : 'Your biggest issue';
      teaserFreePeek.innerHTML =
        `<div class="teaser-peek-label">${teaserLabel}</div>` +
        `<div class="teaser-peek-factor">${label}</div>` +
        `<span class="teaser-peek-score">${peek.score}/10</span>` +
        `<p class="teaser-peek-reasoning">${peek.reasoning || ''}</p>`;
      const hiddenCount = Math.max(0, count - 1);
      teaserHiddenCount.textContent =
        hiddenCount > 0
          ? `+${hiddenCount} more ${hiddenCount === 1 ? 'issue' : 'issues'}`
          : 'See all 10 factors';
      // Change blur label if no more hidden issues
      const blurLabel = document.querySelector('.teaser-blur-label');
      if (blurLabel) {
        blurLabel.innerHTML =
          hiddenCount > 0
            ? `+${hiddenCount} more ${hiddenCount === 1 ? 'issue' : 'issues'} — enter your email to unlock`
            : 'See your full 10-factor breakdown — enter your email';
      }
      show(issueTeaser);
    }

    // CTAs — pre-build links with domain prefill
    const encodedDomain = encodeURIComponent('https://' + domain);
    if (ctaFullAudit) ctaFullAudit.href = '/?domain=' + encodedDomain + '#order';
    if (ctaAuditFix) ctaAuditFix.href = '/?domain=' + encodedDomain + '&product=audit_fix#order';

    // Expose state for exit-intent popup
    window.__af_scan_domain = domain;
    window.__af_cta_clicked = false;
    if (ctaFullAudit) ctaFullAudit.addEventListener('click', () => { window.__af_cta_clicked = true; });
    if (ctaAuditFix) ctaAuditFix.addEventListener('click', () => { window.__af_cta_clicked = true; });

    // Currency switching — read country from config, swap data-* attributes
    applyPricingCurrency(cfg.countryCode || 'US');

    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function showFactorBreakdown(result) {
    hide(emailGate);
    // Reveal the below-fold results section and scroll to it
    const resultsMain = document.getElementById('results-main');
    if (resultsMain) {
      resultsMain.style.display = '';
      setTimeout(() => resultsMain.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
    }
    show(factorBreakdown);

    // Factor list
    factorList.innerHTML = '';
    const summary = result.factor_summary || {};
    for (const [key, status] of Object.entries(summary)) {
      const label = FACTOR_LABELS[key] || key.replace(/_/g, ' ');
      const statusLabel = STATUS_LABELS[status] || status;

      const row = document.createElement('div');
      row.className = 'factor-row';
      row.innerHTML =
        `<span class="factor-dot ${status}"></span>` +
        `<span class="factor-name">${label}</span>` +
        `<span class="factor-status ${status}">${statusLabel}</span>`;
      factorList.appendChild(row);
    }

    // Free peek
    if (result.free_peek) {
      const peek = result.free_peek;
      const label = FACTOR_LABELS[peek.factor] || peek.factor.replace(/_/g, ' ');
      freePeekFactor.textContent = label + ' — ' + peek.score + '/10';
      freePeekScore.textContent = peek.score + '/10';

      // Animate bar after a short delay
      setTimeout(() => {
        freePeekBar.style.width = peek.score * 10 + '%';
      }, 100);

      if (peek.reasoning) {
        freePeekReason.textContent = peek.reasoning;
      }

      show(freePeek);
    }

    // JS-heavy note
    if (result.is_js_heavy) {
      show(jsHeavyNote);
    }
  }

  // ── Scan form submit ──────────────────────────────────────────────────────

  scanForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    hideError(scanError);

    const rawUrl = scanUrl.value.trim();
    if (!rawUrl) {
      showError(scanError, 'Please enter a website URL.');
      return;
    }

    const url = normaliseUrl(rawUrl);
    if (!url) {
      showError(scanError, 'Please enter a valid URL (e.g. yourbusiness.com).');
      return;
    }

    const domain = extractDomain(url);
    scanBtn.disabled = true;
    scanBtn.textContent = 'Analysing…';

    showScanning(domain);

    try {
      const result = await callApi('free-scan', {
        url,
        utm_source: cfg.utmSource || undefined,
        utm_medium: cfg.utmMedium || undefined,
        utm_campaign: cfg.utmCampaign || undefined,
        ref: cfg.ref || undefined,
      });

      currentScanId = result.scan_id;
      currentResult = result;

      showResults(result);
    } catch (err) {
      hide(stageScanning);
      show(stageInput);
      scanBtn.disabled = false;
      scanBtn.textContent = 'Score My Website';
      const errMsg = err.message || 'Something went wrong — please try again.';
      const statusEl = document.getElementById('scan-status');
      if (statusEl) statusEl.textContent = 'Scan failed: ' + errMsg;
      showError(scanError, errMsg);
    }
  });

  // ── Email form submit ─────────────────────────────────────────────────────

  emailForm.addEventListener('submit', async function (e) {
    e.preventDefault();
    hideError(emailError);

    const email = emailInput.value.trim();
    if (!email || !email.includes('@')) {
      showError(emailError, 'Please enter a valid email address.');
      return;
    }

    if (!currentScanId) {
      showError(emailError, 'Session expired — please re-scan your site.');
      return;
    }

    emailBtn.disabled = true;
    emailBtn.textContent = 'Saving…';

    const optinChecked = document.getElementById('email-optin');
    const marketingOptin = optinChecked ? optinChecked.checked : false;

    try {
      const r = currentResult || {};
      const domain = r.domain || extractDomain(r.url || '');
      // Build a compact factor_summary: { factor_key: score, ... }
      const factorSummary = r.factors
        ? JSON.stringify(Object.fromEntries(r.factors.map(f => [f.factor, f.score])))
        : undefined;
      await callApi('save-email', {
        scan_id: currentScanId,
        email,
        marketing_optin: marketingOptin,
        optin_timestamp: marketingOptin ? new Date().toISOString() : undefined,
        score: r.score,
        grade: r.grade,
        domain: domain || undefined,
        issues_count: typeof r.issues_count === 'number' ? r.issues_count : undefined,
        factor_summary: factorSummary,
      });
      showFactorBreakdown(currentResult);
    } catch {
      // If save-email fails, still show breakdown (don't block the user)
      showFactorBreakdown(currentResult);
    }
  });

  // ── Pre-fill URL if passed via query param ────────────────────────────────

  const params = new URLSearchParams(window.location.search);
  const preUrl = params.get('url');
  if (preUrl && scanUrl && !scanUrl.value) {
    scanUrl.value = preUrl;
  }
})();
