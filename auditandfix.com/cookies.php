<?php
$pageTitle = 'Cookie Policy — Audit&Fix';
$pageDescription = 'Information about cookies and tracking used on auditandfix.com.';
require_once __DIR__ . '/includes/legal-header.php';
?>

<h1>Cookie Policy</h1>
<p class="legal-meta">Effective Date: 1 March 2026 &nbsp;·&nbsp; Last Updated: 23 March 2026</p>

<p>This Cookie Policy explains how <strong>Audit&amp;Fix</strong> uses cookies and similar technologies on www.auditandfix.com.</p>

<h2>Cookies We Use</h2>

<h3>Strictly Necessary (no consent required)</h3>
<p><strong><code>af_lang</code></strong> — A session cookie that remembers your chosen language. Cleared when you close your browser. No consent required under GDPR and the ePrivacy Directive.</p>
<p><strong><code>af_consent</code></strong> — Stores your cookie consent choice for 365 days so we do not ask again on return visits.</p>
<p>We do not set cookies for the discount timer. Your first-visit discount is tracked server-side using a one-way hash of your IP address, discarded after 20 minutes.</p>

<h3>Analytics Cookies (consent required)</h3>
<p>If you click <strong>"Accept"</strong> on our cookie banner, we activate <strong>Google Analytics 4</strong> (property <code>G-QMPMDVQJGP</code>, via Google Tag Manager) to understand how visitors use the site. Google Analytics sets:</p>
<ul>
    <li><strong><code>_ga</code></strong> — Distinguishes users. Expires after 2 years.</li>
    <li><strong><code>_ga_QMPMDVQJGP</code></strong> — Session state for our GA4 property. Expires after 2 years.</li>
</ul>
<p>Google Analytics data is processed by Google LLC (US). Google is certified under the EU–US Data Privacy Framework. Data is used only to improve this website and is not shared with third parties for advertising. You can opt out at any time using the button below, or via the <a href="https://tools.google.com/dlpage/gaoptout" target="_blank" rel="noopener">Google Analytics opt-out browser add-on</a>.</p>
<p>If you click <strong>"Decline"</strong>, Google Analytics is not activated and no analytics cookies are set.</p>

<h3>Payment Processing (strictly necessary)</h3>
<p>When you click the payment button, the <strong>PayPal</strong> JavaScript SDK loads. PayPal may set cookies for session management and fraud prevention. These are strictly necessary to process your payment and are governed by <a href="https://www.paypal.com/webapps/mpp/ua/cookie-full" target="_blank" rel="noopener">PayPal's Cookie Policy</a>.</p>

<h2>Your Consent &amp; Managing Preferences</h2>
<p>When you first visit auditandfix.com, a cookie banner will ask for your consent to analytics cookies. You can change your choice at any time:</p>
<p>
    <button onclick="document.cookie='af_consent=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;'; var b=document.getElementById('af-consent-banner'); if(b){b.style.display='flex';}else{location.reload();}" style="display:inline-block;padding:8px 18px;border:1px solid #1a365d;border-radius:6px;color:#1a365d;background:transparent;cursor:pointer;font-size:0.9rem;font-weight:600;">Manage Cookie Preferences</button>
</p>

<h2>Email Tracking</h2>
<p>Emails we send (order confirmations, report delivery) may contain a tracking pixel from <a href="https://resend.com" target="_blank" rel="noopener">Resend.com</a>. This is an image request — not a cookie — that indicates whether the email was opened. You can disable it by turning off automatic image loading in your email client.</p>

<h2>Summary</h2>
<table>
    <thead>
        <tr><th>Cookie / Tracker</th><th>Set by</th><th>Purpose</th><th>Consent required?</th><th>Opt out</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><code>af_lang</code></td>
            <td>auditandfix.com</td>
            <td>Language preference (session)</td>
            <td>No — strictly functional</td>
            <td>Disable cookies in browser</td>
        </tr>
        <tr>
            <td><code>af_consent</code></td>
            <td>auditandfix.com</td>
            <td>Stores your cookie consent choice</td>
            <td>No — strictly functional</td>
            <td>Clear browser cookies</td>
        </tr>
        <tr>
            <td><code>_ga</code>, <code>_ga_QMPMDVQJGP</code></td>
            <td>Google Analytics 4</td>
            <td>Usage analytics (pages, sessions)</td>
            <td><strong>Yes</strong></td>
            <td>Decline or withdraw consent above</td>
        </tr>
        <tr>
            <td>Payment session cookies</td>
            <td>PayPal</td>
            <td>Payment processing (strictly necessary)</td>
            <td>No — required to purchase</td>
            <td>Not possible and still purchase</td>
        </tr>
        <tr>
            <td>Email open pixel</td>
            <td>Resend.com</td>
            <td>Email delivery confirmation</td>
            <td>No — not a cookie</td>
            <td>Disable images in email client</td>
        </tr>
    </tbody>
</table>

<h2>Changes to This Policy</h2>
<p>We will update this Cookie Policy if our technology stack changes. Updates will be posted on this page with a new "Last Updated" date.</p>

<h2>Contact</h2>
<p>Questions about our cookie practices? Email <a href="mailto:<?= htmlspecialchars(LEGALS_EMAIL) ?>"><?= htmlspecialchars(LEGALS_EMAIL) ?></a>.</p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
