<?php
$pageTitle = 'Cookie Policy — Audit&Fix';
$pageDescription = 'Information about cookies and tracking used on auditandfix.com.';
require_once __DIR__ . '/includes/legal-header.php';
?>

<h1>Cookie Policy</h1>
<p class="legal-meta">Effective Date: 1 March 2026 &nbsp;·&nbsp; Last Updated: 17 March 2026</p>

<p>This Cookie Policy explains how <strong>Audit&amp;Fix</strong> uses cookies and similar technologies on www.auditandfix.com.</p>

<h2>Do We Use Cookies?</h2>
<p>We set <strong>one functional first-party cookie</strong> on this website: a language preference cookie (<code>af_lang</code>) that remembers your chosen language for the current browser session. We do not use analytics cookies, advertising cookies, or social media pixels.</p>
<p>We do not set any first-party cookies for the discount timer. Your first-visit discount is tracked server-side using a privacy-safe fingerprint (a one-way hash of your IP address). This information is never stored in a cookie and is automatically discarded after 20 minutes.</p>

<h2>Why There Is No Cookie Banner</h2>
<p>Under GDPR and the ePrivacy Directive, a consent banner is required for non-essential cookies — that is, cookies not strictly necessary for a service you have explicitly requested. Our <code>af_lang</code> language preference cookie is a session cookie that is cleared when you close your browser and is strictly functional. If you prefer not to receive this cookie, you may disable cookies in your browser settings.</p>

<h2>Third-Party Cookies (Payment Processing)</h2>
<p>When you click the payment button on our checkout page, the <strong>PayPal</strong> JavaScript SDK loads. PayPal may set cookies on your device to:</p>
<ul>
    <li>Maintain your PayPal session during the payment process</li>
    <li>Detect and prevent fraud</li>
    <li>Remember your preferred payment method</li>
</ul>
<p>These cookies are <strong>strictly necessary</strong> for processing your payment. You cannot opt out of them and still complete a purchase. They are governed by <a href="https://www.paypal.com/webapps/mpp/ua/cookie-full" target="_blank" rel="noopener">PayPal's Cookie Policy</a>.</p>
<p>The PayPal payment button loads when you scroll to the checkout section of our page. PayPal may set cookies at that point for fraud prevention and session management.</p>

<h2>Email Tracking</h2>
<p>Emails we send (order confirmations, report delivery) contain a small tracking pixel provided by <a href="https://resend.com" target="_blank" rel="noopener">Resend.com</a>. This is not a cookie — it is an image request that tells us whether the email was opened. You can disable this by turning off automatic image loading in your email client.</p>

<h2>Summary</h2>
<table>
    <thead>
        <tr><th>Cookie / Tracker</th><th>Set by</th><th>Purpose</th><th>Can you opt out?</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><code>af_lang</code></td>
            <td>auditandfix.com</td>
            <td>Remembers your language preference for the current browser session</td>
            <td>Yes — disable cookies in your browser</td>
        </tr>
        <tr>
            <td>Payment session cookies</td>
            <td>PayPal</td>
            <td>Payment processing (strictly necessary)</td>
            <td>No — required to purchase</td>
        </tr>
        <tr>
            <td>Email open pixel</td>
            <td>Resend.com</td>
            <td>Email delivery tracking</td>
            <td>Yes — disable images in your email client</td>
        </tr>
    </tbody>
</table>

<h2>Changes to This Policy</h2>
<p>We may update this Cookie Policy if our technology stack changes. Updates will be posted on this page with a new "Last Updated" date.</p>

<h2>Contact</h2>
<p>Questions about our cookie practices? Email <a href="mailto:<?= htmlspecialchars(LEGALS_EMAIL) ?>"><?= htmlspecialchars(LEGALS_EMAIL) ?></a>.</p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
