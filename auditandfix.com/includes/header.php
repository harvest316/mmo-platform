<?php
/**
 * Shared site header — sticky nav with responsive hamburger menu.
 *
 * Include on any page:
 *   <?php require_once __DIR__ . '/../includes/header.php'; ?>
 *   (adjust relative path from subdirectories)
 *
 * Optional variables (set before including):
 *   $headerTheme  string  'dark' (default, light logo on dark bg) or 'light'
 *   $headerCta    array   ['text' => '...', 'href' => '...'] — override CTA button
 *   $headerBanner string  Raw HTML for a banner strip inside the sticky header (e.g. deal countdown)
 *
 * Self-contained: all CSS and JS are inline.
 */

// Bootstrap i18n if not already loaded (safe with require_once)
require_once __DIR__ . '/i18n.php';

// Defaults
$_headerTheme    = $headerTheme ?? 'dark';
$_headerBanner   = $headerBanner ?? '';
$_hideLang       = $hideLangSelector ?? false;
$_headerSolidBg  = $headerSolidBg ?? false;  // force opaque bg from the start (no transparent-on-top)

// CTA: page-level override wins, then UTM/referer detection, then default
if (isset($headerCta)) {
    $_headerCtaText = $headerCta['text'];
    $_headerCtaHref = $headerCta['href'];
} else {
    $_utmSource   = $_GET['utm_source'] ?? '';
    $_utmCampaign = $_GET['utm_campaign'] ?? '';
    $_referer     = $_SERVER['HTTP_REFERER'] ?? '';
    $_isVideo = (
        stripos($_utmSource, 'video') !== false
        || stripos($_utmCampaign, 'video') !== false
        || stripos($_utmCampaign, 'review') !== false
        || stripos($_referer, '/video-reviews') !== false
    );
    if ($_isVideo) {
        $_headerCtaText = 'Get Your Free Video';
        $_headerCtaHref = '/video-reviews/';
    } else {
        $_headerCtaText = 'Get Your Free Scan';
        $_headerCtaHref = '/scan';
    }
}
?>

<style>
/* ── Shared Header / Hamburger Menu ─────────────────────────────────────── */

/* Sticky header bar */
.site-header {
    position: sticky;
    top: 0;
    z-index: 1000;
    background: transparent;
    transition: background 0.3s ease, box-shadow 0.3s ease;
}

.site-header--scrolled,
.site-header--solid {
    background: rgba(10, 20, 40, 0.95);
    box-shadow: 0 2px 20px rgba(0, 0, 0, 0.25);
}

.site-header__inner {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
}

/* Logo */
.site-header__logo {
    display: flex;
    align-items: center;
    text-decoration: none;
    z-index: 1002;
}

/* Stack both logo variants in the same grid cell for crossfade */
.site-header__logo-imgs {
    display: grid;
    height: 36px;
}

.site-header__logo-on-dark,
.site-header__logo-on-light {
    grid-row: 1;
    grid-column: 1;
    height: 36px;
    width: auto;
    transition: opacity 0.35s ease;
}

/* Default (dark hero / always-dark header): show white logo */
.site-header__logo-on-dark  { opacity: 1; }
.site-header__logo-on-light { opacity: 0; pointer-events: none; }

/* Light-hero pages: at top show coloured logo, on scroll show white */
.site-header--light-hero:not(.site-header--scrolled) .site-header__logo-on-dark  { opacity: 0; pointer-events: none; }
.site-header--light-hero:not(.site-header--scrolled) .site-header__logo-on-light { opacity: 1; pointer-events: auto; }

.site-header__logo-text {
    font-size: 22px;
    font-weight: 700;
    color: #ffffff;
    letter-spacing: -0.5px;
}

.site-header__logo-amp {
    color: #e05d26;
}

/* Right-side group (lang + hamburger) */
.site-header__right {
    display: flex;
    align-items: center;
    gap: 12px;
    z-index: 1002;
}

/* Language selector */
.site-header__lang {
    appearance: none;
    -webkit-appearance: none;
    background: rgba(255, 255, 255, 0.12);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 6px;
    padding: 6px 28px 6px 10px;
    font-size: 0.78rem;
    cursor: pointer;
    outline: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='rgba(255,255,255,0.7)'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    transition: background 0.2s, border-color 0.2s;
}
.site-header__lang:hover {
    background: rgba(255, 255, 255, 0.2);
    border-color: rgba(255, 255, 255, 0.4);
}
.site-header__lang option {
    background: #1a2a3a;
    color: #ffffff;
}

/* Light-hero: dark lang selector before scroll */
.site-header--light-hero:not(.site-header--scrolled) .site-header__lang {
    background: rgba(0, 0, 0, 0.06);
    color: #1a2a3a;
    border-color: rgba(0, 0, 0, 0.15);
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='rgba(0,0,0,0.5)'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
}
.site-header--light-hero:not(.site-header--scrolled) .site-header__lang:hover {
    background: rgba(0, 0, 0, 0.1);
}

/* Hide on very small screens */
@media (max-width: 400px) {
    .site-header__lang { display: none; }
}

/* Hamburger button */
.site-header__hamburger {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    width: 44px;
    height: 44px;
    padding: 0;
    background: none;
    border: none;
    cursor: pointer;
    z-index: 1002;
    -webkit-tap-highlight-color: transparent;
}

.site-header__hamburger-line {
    display: block;
    width: 24px;
    height: 2px;
    background: #ffffff;
    border-radius: 2px;
    transition: background 0.35s ease,
                transform 0.3s cubic-bezier(0.16, 1, 0.3, 1),
                opacity 0.3s ease;
}

/* Dark lines on light-hero pages before the header darkens */
.site-header--light-hero:not(.site-header--scrolled) .site-header__hamburger-line {
    background: #1a2a3a;
}

.site-header__hamburger-line + .site-header__hamburger-line {
    margin-top: 6px;
}

/* Hamburger -> X morphing */
.site-header__hamburger[aria-expanded="true"] .site-header__hamburger-line:nth-child(1) {
    transform: translateY(8px) rotate(45deg);
}
.site-header__hamburger[aria-expanded="true"] .site-header__hamburger-line:nth-child(2) {
    opacity: 0;
    transform: scaleX(0);
}
.site-header__hamburger[aria-expanded="true"] .site-header__hamburger-line:nth-child(3) {
    transform: translateY(-8px) rotate(-45deg);
}

/* ── Overlay / slide-in panel ───────────────────────────────────────────── */

/* Backdrop */
.site-menu-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.3s ease, visibility 0.3s ease;
}

.site-menu-backdrop--open {
    opacity: 1;
    visibility: visible;
}

/* Panel */
.site-menu-panel {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: 300px;
    max-width: 100vw;
    background: rgba(10, 20, 40, 0.97);
    backdrop-filter: blur(30px) saturate(180%);
    -webkit-backdrop-filter: blur(30px) saturate(180%);
    z-index: 1001;
    transform: translateX(100%);
    transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    flex-direction: column;
    overflow-y: auto;
}

.site-menu-panel--open {
    transform: translateX(0);
}

/* Close button inside panel */
.site-menu-panel__close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 44px;
    height: 44px;
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.7);
    font-size: 28px;
    line-height: 44px;
    text-align: center;
    cursor: pointer;
    border-radius: 8px;
    transition: color 0.2s ease, background 0.2s ease;
    -webkit-tap-highlight-color: transparent;
}

.site-menu-panel__close:hover,
.site-menu-panel__close:focus-visible {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.1);
}

/* Menu navigation */
.site-menu-nav {
    display: flex;
    flex-direction: column;
    padding: 80px 32px 40px;
    flex: 1;
}

.site-menu-nav__link {
    display: block;
    color: rgba(255, 255, 255, 0.85);
    text-decoration: none;
    font-size: 1rem;
    font-weight: 500;
    padding: 16px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    transition: color 0.2s ease, padding-left 0.2s ease;
}

.site-menu-nav__link:hover,
.site-menu-nav__link:focus-visible {
    color: #ffffff;
    text-decoration: none;
    padding-left: 8px;
}

/* Child (indented sub-link with corner icon) */
.site-menu-nav__link--child {
    padding-left: 20px;
    font-size: 0.9rem;
    font-weight: 400;
    color: rgba(255, 255, 255, 0.6);
    padding-top: 10px;
    padding-bottom: 10px;
}
.site-menu-nav__link--child::before {
    content: "\2514";
    margin-right: 8px;
    color: rgba(255, 255, 255, 0.3);
    font-size: 0.85em;
}
.site-menu-nav__link--child:hover,
.site-menu-nav__link--child:focus-visible {
    padding-left: 26px;
}

/* Personal links (Your Free Video / Audit) */
.site-menu-nav__link--personal {
    color: #fbd38d;
    font-weight: 600;
}
.site-menu-nav__link--personal::before {
    content: "\2605";
    margin-right: 6px;
    font-size: 0.8em;
}

.site-menu-nav__link:last-child {
    border-bottom: none;
}

/* CTA inside menu */
.site-menu-nav__cta {
    display: inline-block;
    background: #e05d26;
    color: #ffffff;
    text-align: center;
    padding: 14px 28px;
    border-radius: 6px;
    font-size: 1rem;
    font-weight: 600;
    text-decoration: none;
    margin-top: 24px;
    transition: background 0.2s ease, transform 0.15s ease;
}

.site-menu-nav__cta:hover,
.site-menu-nav__cta:focus-visible {
    background: #c44d1e;
    text-decoration: none;
    transform: translateY(-1px);
}

/* ── Mobile overrides ───────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .site-header__inner {
        padding: 12px 20px;
    }

    .site-menu-panel {
        width: 100vw;
    }

    .site-menu-nav {
        padding: 80px 24px 40px;
        align-items: center;
        text-align: center;
    }

    .site-menu-nav__link {
        font-size: 1.2rem;
        padding: 20px 0;
    }

    .site-menu-nav__cta {
        width: 100%;
        max-width: 280px;
    }
}

/* Focus visible outline for accessibility */
.site-header__hamburger:focus-visible,
.site-menu-panel__close:focus-visible,
.site-menu-nav__link:focus-visible,
.site-menu-nav__cta:focus-visible,
.site-header__logo:focus-visible {
    outline: 2px solid #63b3ed;
    outline-offset: 2px;
}
</style>

<header class="site-header<?= $_headerTheme === 'light' ? ' site-header--light-hero' : '' ?><?= $_headerSolidBg ? ' site-header--solid' : '' ?>" id="site-header">
    <div class="site-header__inner">
        <a href="/" class="site-header__logo">
            <span class="site-header__logo-imgs">
                <img src="/assets/img/logo.svg" alt="Audit&amp;Fix" class="site-header__logo-on-dark">
                <img src="/assets/img/logo-light.svg" alt="" aria-hidden="true" class="site-header__logo-on-light">
            </span>
            <span class="site-header__logo-text" style="display:none">Audit<span class="site-header__logo-amp">&amp;</span>Fix</span>
        </a>

        <?= $_headerBanner ?>

        <div class="site-header__right">
            <?php if (!$_hideLang): ?>
            <select class="site-header__lang" aria-label="Language" onchange="var p=new URLSearchParams(window.location.search);p.set('lang',this.value);window.location.href=window.location.pathname+'?'+p.toString()+(window.location.hash||'')">
                <?php foreach (langNames() as $code => $name): ?>
                <option value="<?= htmlspecialchars($code) ?>"<?= $code === $lang ? ' selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

        <button
            class="site-header__hamburger"
            id="site-header-hamburger"
            type="button"
            aria-expanded="false"
            aria-controls="site-menu-panel"
            aria-label="Open menu"
        >
            <span class="site-header__hamburger-line"></span>
            <span class="site-header__hamburger-line"></span>
            <span class="site-header__hamburger-line"></span>
        </button>
        </div>
    </div>
</header>

<!-- Slide-in menu -->
<div class="site-menu-backdrop" id="site-menu-backdrop" aria-hidden="true"></div>
<nav
    class="site-menu-panel"
    id="site-menu-panel"
    role="dialog"
    aria-label="Site menu"
    aria-hidden="true"
>
    <button
        class="site-menu-panel__close"
        id="site-menu-close"
        type="button"
        aria-label="Close menu"
    >&times;</button>

    <div class="site-menu-nav">
        <a href="/" class="site-menu-nav__link">Home</a>
        <a href="/scan" class="site-menu-nav__link">Conversion Audit</a>
        <a href="/compare" class="site-menu-nav__link site-menu-nav__link--child">CRO Audit Comparison</a>
        <a href="/methodology" class="site-menu-nav__link site-menu-nav__link--child">Scoring Methodology</a>
        <a href="/video-reviews/" class="site-menu-nav__link">Review Videos</a>
        <a href="/video-reviews/compare" class="site-menu-nav__link site-menu-nav__link--child">Video Comparison</a>
        <a href="/blog/" class="site-menu-nav__link">Blog</a>
        <?php if (!empty($_SESSION['customer_id'])): ?>
        <a href="/account/dashboard" class="site-menu-nav__link site-menu-nav__link--personal">My Account</a>
        <?php else: ?>
        <a href="/account/login" class="site-menu-nav__link">Login</a>
        <?php endif; ?>
        <?php if (!empty($_SESSION['my_video']['hash'])): ?>
        <a href="/v/<?= htmlspecialchars($_SESSION['my_video']['hash']) ?>" class="site-menu-nav__link site-menu-nav__link--personal">Your Free Video</a>
        <?php endif; ?>
        <?php if (!empty($_SESSION['my_audit']['id'])): ?>
        <a href="/a/<?= htmlspecialchars($_SESSION['my_audit']['id']) ?>" class="site-menu-nav__link site-menu-nav__link--personal">Your Free Audit</a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($_headerCtaHref) ?>" class="site-menu-nav__cta"><?= htmlspecialchars($_headerCtaText) ?> &rarr;</a>
    </div>
</nav>

<script>
(function() {
    'use strict';

    var hamburger = document.getElementById('site-header-hamburger');
    var panel     = document.getElementById('site-menu-panel');
    var backdrop  = document.getElementById('site-menu-backdrop');
    var closeBtn  = document.getElementById('site-menu-close');
    var header    = document.getElementById('site-header');

    if (!hamburger || !panel || !backdrop || !closeBtn || !header) return;

    // Focusable elements inside the menu panel
    var focusableSelector = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';
    var isOpen = false;

    function openMenu() {
        isOpen = true;
        hamburger.setAttribute('aria-expanded', 'true');
        hamburger.setAttribute('aria-label', 'Close menu');
        panel.classList.add('site-menu-panel--open');
        panel.setAttribute('aria-hidden', 'false');
        backdrop.classList.add('site-menu-backdrop--open');
        backdrop.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        // Focus the close button after transition
        setTimeout(function() {
            closeBtn.focus();
        }, 100);
    }

    function closeMenu() {
        isOpen = false;
        hamburger.setAttribute('aria-expanded', 'false');
        hamburger.setAttribute('aria-label', 'Open menu');
        panel.classList.remove('site-menu-panel--open');
        panel.setAttribute('aria-hidden', 'true');
        backdrop.classList.remove('site-menu-backdrop--open');
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';

        // Return focus to hamburger
        hamburger.focus();
    }

    // Toggle on hamburger click
    hamburger.addEventListener('click', function() {
        if (isOpen) {
            closeMenu();
        } else {
            openMenu();
        }
    });

    // Close on X button
    closeBtn.addEventListener('click', closeMenu);

    // Close on backdrop click
    backdrop.addEventListener('click', closeMenu);

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && isOpen) {
            closeMenu();
        }
    });

    // Focus trap inside open menu
    panel.addEventListener('keydown', function(e) {
        if (e.key !== 'Tab' || !isOpen) return;

        var focusables = panel.querySelectorAll(focusableSelector);
        if (focusables.length === 0) return;

        var first = focusables[0];
        var last  = focusables[focusables.length - 1];

        if (e.shiftKey) {
            // Shift+Tab: if on first element, wrap to last
            if (document.activeElement === first) {
                e.preventDefault();
                last.focus();
            }
        } else {
            // Tab: if on last element, wrap to first
            if (document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    // Close menu when a link inside is clicked (navigate)
    var menuLinks = panel.querySelectorAll('.site-menu-nav__link, .site-menu-nav__cta');
    for (var i = 0; i < menuLinks.length; i++) {
        menuLinks[i].addEventListener('click', function() {
            closeMenu();
        });
    }

    // ── Scroll detection: add solid background after 50px ───────────────
    var scrollThreshold = 50;
    var ticking = false;

    function onScroll() {
        if (window.scrollY > scrollThreshold) {
            header.classList.add('site-header--scrolled');
        } else {
            header.classList.remove('site-header--scrolled');
        }
        ticking = false;
    }

    window.addEventListener('scroll', function() {
        if (!ticking) {
            requestAnimationFrame(onScroll);
            ticking = true;
        }
    }, { passive: true });

    // Run once on load in case already scrolled
    onScroll();
})();
</script>
