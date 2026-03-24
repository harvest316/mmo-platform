/**
 * Video-on-Demand — Demo flow state machine
 *
 * Handles: business form → email gate → confirmation/polling → video reveal.
 * Communicates with: api.php?action=request-demo | demo-email | demo-status
 *
 * States:
 *   1. Form visible (initial) — Google Places Autocomplete, niche select
 *   2. Email gate — captures email after demo request
 *   3. Confirmation / polling — progress animation + poll for video readiness
 *   4. Video ready — embedded player + pricing CTAs
 */

(function () {
  'use strict';

  var cfg = window.VOD_CONFIG || {};

  // ── State ──────────────────────────────────────────────────────────────

  var currentDemoId = null;
  var currentBusinessName = null;
  var currentPlaceId = null;
  var currentCity = '';
  var currentHasClips = false;
  var pollTimer = null;
  var pollStartTime = 0;
  var pollCount = 0;

  // ── DOM refs ───────────────────────────────────────────────────────────

  var $ = function (id) { return document.getElementById(id); };

  var formSection        = $('get-video');           // Section wrapper
  var formContainer      = $('vod-form-container');  // Form card
  var form               = $('vod-form');
  var formBtn            = $('vod-form-btn');
  var formError          = $('vod-form-error');
  var businessNameInput  = $('vod-business-name');
  var nicheSelect        = $('vod-niche');
  var otherNicheGroup    = $('vod-other-niche-group');
  var otherNicheInput    = $('vod-other-niche');
  var countrySelect      = $('vod-country');

  var emailSection       = $('vod-email-section');
  var emailForm          = $('vod-email-form');
  var emailInput         = $('vod-email-input');
  var emailBtn           = $('vod-email-btn');
  var emailError         = $('vod-email-error');

  var confirmSection     = $('vod-confirmation-section');
  var confirmTitle       = $('vod-confirmation-title');
  var confirmDesc        = $('vod-confirmation-desc');

  // ── Helpers ─────────────────────────────────────────────────────────────

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

  function getUtmParams() {
    var params = new URLSearchParams(window.location.search);
    return {
      utm_source:   params.get('utm_source')   || undefined,
      utm_medium:   params.get('utm_medium')    || undefined,
      utm_campaign: params.get('utm_campaign')  || undefined,
    };
  }

  function smoothScrollTo(el) {
    if (!el) return;
    setTimeout(function () {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 100);
  }

  // ── API caller ──────────────────────────────────────────────────────────

  async function callApi(action, body) {
    var res = await fetch(cfg.apiBase + '/api.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    var data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  // ── Section transitions ─────────────────────────────────────────────────

  function showSection(id) {
    // Hide the three flow sections
    hide(formSection);
    hide(emailSection);
    hide(confirmSection);

    // Show the requested one
    var target = $(id);
    if (target) {
      show(target);
      smoothScrollTo(target);
    }
  }

  // ── Google Places Autocomplete ──────────────────────────────────────────

  var autocomplete = null;

  window.initPlacesAutocomplete = function () {
    if (!businessNameInput) return;

    var countryCode = (cfg.countryCode || 'AU').toUpperCase();
    // Google Places expects ISO 3166-1 alpha-2; map UK -> GB
    var placesCountry = countryCode === 'UK' ? 'GB' : countryCode;

    try {
      autocomplete = new google.maps.places.Autocomplete(businessNameInput, {
        types: ['establishment'],
        componentRestrictions: { country: [placesCountry] },
        fields: ['place_id', 'name', 'address_components', 'formatted_address'],
      });

      autocomplete.addListener('place_changed', function () {
        var place = autocomplete.getPlace();
        if (!place || !place.place_id) return;

        currentPlaceId = place.place_id;

        // Use the official name from Places API
        if (place.name) {
          businessNameInput.value = place.name;
          currentBusinessName = place.name;
        }

        // Extract city from address components
        currentCity = '';
        if (place.address_components) {
          for (var i = 0; i < place.address_components.length; i++) {
            var comp = place.address_components[i];
            if (comp.types.indexOf('locality') !== -1) {
              currentCity = comp.long_name;
              break;
            }
            // Fallback: some areas don't have 'locality'
            if (!currentCity && comp.types.indexOf('administrative_area_level_2') !== -1) {
              currentCity = comp.long_name;
            }
          }
        }
      });

      // Update country restriction when country dropdown changes
      if (countrySelect) {
        countrySelect.addEventListener('change', function () {
          var newCountry = this.value === 'UK' ? 'GB' : this.value;
          autocomplete.setComponentRestrictions({ country: [newCountry] });
          // Clear previous selection since country changed
          currentPlaceId = null;
          currentCity = '';
        });
      }
    } catch (err) {
      // Google Places API failed to load — degrade gracefully.
      // The form will still work; place_id will just be null.
      console.warn('Places Autocomplete init failed:', err);
    }
  };

  // ── State 1: Form submission ────────────────────────────────────────────

  if (form) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      hideError(formError);

      // Gather values
      var businessName = (businessNameInput ? businessNameInput.value.trim() : '');
      var niche = (nicheSelect ? nicheSelect.value : '');
      var country = (countrySelect ? countrySelect.value : cfg.countryCode || 'AU');

      // If niche is "other", use the free-text field
      if (niche === 'other') {
        var otherVal = otherNicheInput ? otherNicheInput.value.trim() : '';
        if (!otherVal) {
          showError(formError, 'Please tell us your industry.');
          if (otherNicheInput) otherNicheInput.focus();
          return;
        }
        niche = otherVal;
      }

      // Validation
      if (!businessName) {
        showError(formError, 'Please enter your business name.');
        if (businessNameInput) businessNameInput.focus();
        return;
      }
      if (!currentPlaceId) {
        showError(formError, 'Please select your business from the dropdown suggestions so we can find your Google reviews.');
        if (businessNameInput) businessNameInput.focus();
        return;
      }
      if (!niche) {
        showError(formError, 'Please select your industry.');
        if (nicheSelect) nicheSelect.focus();
        return;
      }

      // Disable button
      formBtn.disabled = true;
      formBtn.textContent = 'Finding your reviews\u2026';

      var utmParams = getUtmParams();

      try {
        var result = await callApi('request-demo', {
          business_name: businessName,
          place_id: currentPlaceId,
          niche: niche,
          city: currentCity,
          country_code: country,
          utm_source: utmParams.utm_source,
          utm_medium: utmParams.utm_medium,
          utm_campaign: utmParams.utm_campaign,
        });

        currentDemoId = result.demo_id;
        currentBusinessName = businessName;
        currentHasClips = !!result.has_clips;

        // Transition to State 2: email gate
        transitionToEmail();
      } catch (err) {
        formBtn.disabled = false;
        formBtn.textContent = 'Get Your Free Video \u2192';
        var errMsg = err.message || 'Something went wrong \u2014 please try again.';
        showError(formError, errMsg);
      }
    });
  }

  // ── State 2: Email gate ─────────────────────────────────────────────────

  function transitionToEmail() {
    showSection('vod-email-section');

    // Personalise heading with business name
    var emailHeading = emailSection ? emailSection.querySelector('h2') : null;
    if (emailHeading && currentBusinessName) {
      emailHeading.textContent = 'We found ' + currentBusinessName + '! Enter your email to get your free video.';
    }
  }

  if (emailForm) {
    emailForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      hideError(emailError);

      var email = emailInput ? emailInput.value.trim() : '';

      // Basic email validation
      if (!email || !email.includes('@') || !email.includes('.')) {
        showError(emailError, 'Please enter a valid email address.');
        if (emailInput) emailInput.focus();
        return;
      }

      if (!currentDemoId) {
        showError(emailError, 'Session expired \u2014 please refresh and try again.');
        return;
      }

      emailBtn.disabled = true;
      emailBtn.textContent = 'Sending\u2026';

      try {
        await callApi('demo-email', {
          demo_id: currentDemoId,
          email: email,
        });

        // Transition to State 3: confirmation
        transitionToConfirmation();
      } catch (err) {
        emailBtn.disabled = false;
        emailBtn.textContent = 'Send My Video';
        var errMsg = err.message || 'Something went wrong \u2014 please try again.';
        showError(emailError, errMsg);
      }
    });
  }

  // ── State 3: Confirmation / polling ─────────────────────────────────────

  function transitionToConfirmation() {
    showSection('vod-confirmation-section');

    if (currentHasClips) {
      // Branch A: clips available, video creation started — poll for it
      if (confirmTitle) {
        confirmTitle.textContent = 'Your video is being created!';
      }
      if (confirmDesc) {
        confirmDesc.textContent = 'We\u2019re building your personalised video right now. This usually takes a minute or two.';
      }

      // Show progress bar animation
      var progressBar = confirmSection ? confirmSection.querySelector('.vod-progress-bar') : null;
      if (progressBar) show(progressBar);

      var progressLabel = confirmSection ? confirmSection.querySelector('.vod-progress-label') : null;
      if (progressLabel) {
        progressLabel.textContent = 'Scanning your reviews and creating your video\u2026';
        show(progressLabel);
      }

      startPolling();
    } else {
      // Branch B: no clips yet — we'll create it asynchronously and email them
      if (confirmTitle) {
        confirmTitle.textContent = 'We\u2019ll create your personalised video shortly and email it to you.';
      }
      if (confirmDesc) {
        confirmDesc.textContent = 'We need to scan your reviews and create the video first. You\u2019ll receive an email with a link to your video within 24 hours.';
      }

      // Hide the progress bar for Branch B
      var progressBar = confirmSection ? confirmSection.querySelector('.vod-progress-bar') : null;
      if (progressBar) hide(progressBar);

      var progressLabel = confirmSection ? confirmSection.querySelector('.vod-progress-label') : null;
      if (progressLabel) hide(progressLabel);
    }
  }

  // ── Polling logic ──────────────────────────────────────────────────────

  function startPolling() {
    pollStartTime = Date.now();
    pollCount = 0;
    schedulePoll();
  }

  function schedulePoll() {
    var elapsed = Date.now() - pollStartTime;

    // After 5 minutes, stop polling and show fallback message
    if (elapsed > 5 * 60 * 1000) {
      stopPolling();
      showPollTimeout();
      return;
    }

    // First 2 minutes: poll every 8s. After that: every 15s.
    var interval = elapsed < 2 * 60 * 1000 ? 8000 : 15000;

    pollTimer = setTimeout(doPoll, interval);
  }

  async function doPoll() {
    pollCount++;

    try {
      var result = await callApi('demo-status', {
        demo_id: currentDemoId,
      });

      if (result.status === 'ready' && result.video_url) {
        stopPolling();
        transitionToVideoReady(result.video_url);
        return;
      }

      // Update progress label with poll feedback if provided
      if (result.status_message) {
        var progressLabel = confirmSection ? confirmSection.querySelector('.vod-progress-label') : null;
        if (progressLabel) {
          progressLabel.textContent = result.status_message;
        }
      }
    } catch (err) {
      // Network error during poll — not fatal, just retry
      console.warn('Poll failed:', err.message);
    }

    // Schedule next poll
    schedulePoll();
  }

  function stopPolling() {
    if (pollTimer) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }
  }

  function showPollTimeout() {
    if (confirmTitle) {
      confirmTitle.textContent = 'Your video is taking a bit longer than usual.';
    }
    if (confirmDesc) {
      confirmDesc.textContent = 'Don\u2019t worry \u2014 we\u2019ll email it to you as soon as it\u2019s ready. You can close this page.';
    }

    // Stop the progress bar animation
    var progressFill = confirmSection ? confirmSection.querySelector('.vod-progress-fill') : null;
    if (progressFill) {
      progressFill.style.animation = 'none';
      progressFill.style.width = '92%';
    }

    var progressLabel = confirmSection ? confirmSection.querySelector('.vod-progress-label') : null;
    if (progressLabel) {
      progressLabel.textContent = 'We\u2019ll send you an email when your video is ready.';
    }
  }

  // ── State 4: Video ready ───────────────────────────────────────────────

  function transitionToVideoReady(videoUrl) {
    // Update confirmation section to show the video
    if (confirmTitle) {
      confirmTitle.textContent = 'Your video is ready!';
    }
    if (confirmDesc) {
      confirmDesc.textContent = 'Here\u2019s your personalised review video. Download it and share it anywhere.';
    }

    // Hide progress bar
    var progressBar = confirmSection ? confirmSection.querySelector('.vod-progress-bar') : null;
    if (progressBar) hide(progressBar);
    var progressLabel = confirmSection ? confirmSection.querySelector('.vod-progress-label') : null;
    if (progressLabel) hide(progressLabel);

    // Build video player
    var wrap = confirmSection ? confirmSection.querySelector('.vod-confirmation-wrap') : null;
    if (!wrap) return;

    // Check if we already injected a player (prevent duplicates)
    if (wrap.querySelector('.vod-video-player')) return;

    var playerDiv = document.createElement('div');
    playerDiv.className = 'vod-video-player';
    playerDiv.style.cssText = 'max-width:360px;margin:24px auto;border-radius:12px;overflow:hidden;box-shadow:0 8px 30px rgba(0,0,0,0.12);';

    var video = document.createElement('video');
    video.src = videoUrl;
    video.controls = true;
    video.autoplay = true;
    video.playsInline = true;
    video.style.cssText = 'width:100%;display:block;';
    video.setAttribute('preload', 'auto');

    playerDiv.appendChild(video);
    wrap.appendChild(playerDiv);

    // Add download link
    var downloadLink = document.createElement('a');
    downloadLink.href = videoUrl;
    downloadLink.download = '';
    downloadLink.textContent = 'Download Video';
    downloadLink.className = 'vod-form-btn';
    downloadLink.style.cssText = 'display:inline-block;width:auto;padding:12px 28px;margin-top:16px;text-decoration:none;text-align:center;';
    wrap.appendChild(downloadLink);

    // Add pricing CTA below the video
    var ctaDiv = document.createElement('div');
    ctaDiv.style.cssText = 'margin-top:24px;padding:20px;background:#f7fafc;border-radius:8px;text-align:center;';
    ctaDiv.innerHTML =
      '<p style="color:#4a5568;font-size:0.95rem;margin-bottom:12px;">' +
      'Want more videos like this every month?' +
      '</p>' +
      '<a href="#pricing" class="vod-pricing-cta" style="display:inline-block;">' +
      'See Plans from ' + (cfg.pricing ? cfg.pricing.symbol : '$') + (cfg.pricing ? cfg.pricing.monthly4 : '139') + '/mo' +
      '</a>';
    wrap.appendChild(ctaDiv);

    // Change confirmation icon to a play icon
    var icon = confirmSection ? confirmSection.querySelector('.vod-confirmation-icon') : null;
    if (icon) {
      icon.textContent = '\u25B6';
      icon.style.background = '#2563eb';
    }

    // Smooth scroll to video
    smoothScrollTo(confirmSection);
  }

  // ── Cleanup on page unload ──────────────────────────────────────────────

  window.addEventListener('beforeunload', function () {
    stopPolling();
  });

})();
