<?php
$pageTitle = 'Privacy Policy — Audit&Fix';
$pageDescription = 'How Audit&Fix collects, uses, and protects your personal information.';
require_once __DIR__ . '/includes/legal-header.php';
?>

<h1>Privacy Policy</h1>
<p class="legal-meta">Effective Date: 1 March 2026 &nbsp;·&nbsp; Last Updated: 17 March 2026</p>

<p><strong>Audit&amp;Fix</strong> ("we", "us", "our") operates www.auditandfix.com (the "Service"). This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our Service.</p>

<h2>1. Information We Collect</h2>

<h3>1.1 Information You Provide</h3>
<p>When you purchase or use our Service, we collect:</p>
<ul>
    <li><strong>Email address</strong> – To deliver your CRO audit report and send transaction receipts</li>
    <li><strong>Phone number</strong> (optional) – If you choose to provide it</li>
    <li><strong>Website URL</strong> – The website you want us to analyse</li>
    <li><strong>Payment information</strong> – Processed securely by PayPal (we do not store card details on our servers)</li>
</ul>

<h3>1.1a Free Website Scanner</h3>
<p>When you use our free website scanner at /scan, we collect:</p>
<ul>
    <li><strong>Your website URL</strong> – to perform the analysis</li>
    <li><strong>Your email address</strong> (if you choose to provide it to unlock your full factor breakdown) – to send you your score results and, with your permission, occasional information about improving your website's conversion rate. We will not send you unsolicited commercial messages; you can unsubscribe at any time by replying 'STOP' or emailing us.</li>
</ul>

<h3>1.2 Information Automatically Collected</h3>
<p>We automatically collect limited technical information:</p>
<ul>
    <li><strong>First-visit discount</strong> – Tracked server-side using a temporary, privacy-safe fingerprint (a one-way hash of your IP address, never stored as-is). Automatically discarded after 20 minutes.</li>
    <li><strong>Language preference cookie</strong> (<code>af_lang</code>) – A session cookie that remembers your chosen language for the current browser session. Cleared when you close your browser.</li>
    <li><strong>Email tracking pixel</strong> (Resend.com) – To detect if you opened our delivery emails. You can opt out by disabling image loading in your email client.</li>
    <li><strong>Server access logs</strong> – Standard web server logs (IP address, browser type, page requested) retained for 30 days for security purposes.</li>
</ul>
<p>We <strong>do not</strong> use website analytics software, advertising trackers, or social media pixels.</p>

<h3>1.3 Social Media</h3>
<p>If you contact us via social media (X/Twitter, LinkedIn), we may view your public profile information visible on those platforms.</p>

<h2>2. How We Use Your Information</h2>
<p>We use your information to:</p>
<ul>
    <li><strong>Deliver the Service</strong> – Generate and email your CRO audit report</li>
    <li><strong>Process payments</strong> – Via PayPal (PCI-DSS compliant)</li>
    <li><strong>Provide customer support</strong> – Respond to enquiries and resolve issues</li>
    <li><strong>Send transactional emails</strong> – Order confirmations and delivery notifications. No marketing emails.</li>
    <li><strong>Improve our service</strong> – Aggregated, anonymised data from free scanner usage may be used to improve our scoring methodology.</li>
</ul>
<p>We <strong>do not</strong> sell, rent, or share your personal information with third parties for marketing purposes.</p>

<h2>3. Legal Basis for Processing (GDPR)</h2>
<p>For users in the European Economic Area (EEA), United Kingdom, or Switzerland, we process your personal data under:</p>
<ul>
    <li><strong>Contract performance</strong> – To fulfil our agreement to deliver your report (Art. 6(1)(b) GDPR)</li>
    <li><strong>Legitimate interests</strong> – Server security logs, fraud prevention, and email open tracking pixels (Resend.com) for transactional emails. You can opt out by disabling automatic image loading in your email client. (Art. 6(1)(f) GDPR)</li>
</ul>

<h2>4. Data Retention</h2>
<ul>
    <li><strong>Email and purchase records</strong> – 7 years (Australian tax compliance)</li>
    <li><strong>Website analysis data</strong> – 90 days after report delivery, then permanently deleted</li>
    <li><strong>Payment information</strong> – Stored by PayPal; see <a href="https://www.paypal.com/webapps/mpp/ua/privacy-full" target="_blank" rel="noopener">PayPal Privacy Policy</a></li>
    <li><strong>Server access logs</strong> – 30 days</li>
</ul>

<h2>5. Your Rights</h2>
<p>Under the Australian Privacy Act 1988 and GDPR, you have the right to:</p>
<ul>
    <li><strong>Access</strong> – Request a copy of your personal data</li>
    <li><strong>Rectification</strong> – Correct inaccurate information</li>
    <li><strong>Erasure</strong> – Request deletion (exceptions: legal obligations, tax records)</li>
    <li><strong>Restriction</strong> – Limit how we use your data</li>
    <li><strong>Object</strong> – Opt out of email tracking or certain processing</li>
    <li><strong>Portability</strong> – Request a copy of data you provided to us in a structured, machine-readable format (where processing is based on consent or contract)</li>
    <li><strong>Withdraw consent</strong> – Stop receiving communications (except transactional emails)</li>
</ul>
<p>To exercise these rights, email: <a href="mailto:<?= htmlspecialchars(LEGALS_EMAIL) ?>"><?= htmlspecialchars(LEGALS_EMAIL) ?></a>. We will respond within 30 days.</p>

<h2>6. Data Security</h2>
<p>We implement industry-standard security measures:</p>
<ul>
    <li><strong>Encryption</strong> – All data transmitted via HTTPS/TLS</li>
    <li><strong>Secure payment processing</strong> – PayPal handles all payment data (PCI-DSS certified)</li>
    <li><strong>Access controls</strong> – Limited access to personal data</li>
</ul>
<p>No system is 100% secure. We cannot guarantee absolute security but will notify you of any data breach as required by law.</p>

<h2>7. International Data Transfers</h2>
<p>Our servers are located in Australia and USA. If you are in the EEA, UK, or Switzerland, your data may be transferred to and processed in these countries. For email delivery, we use Resend.com (US-based), whose Standard Contractual Clauses (incorporated into Resend's Data Processing Agreement) provide an appropriate safeguard for any EEA/UK transfers. For payment processing, PayPal maintains its own cross-border data transfer mechanisms. We are a small business with limited EU data processing activity; we assess each transfer against the requirements of GDPR Art. 44–49.</p>

<h2>8. Third-Party Services</h2>
<p>We use the following trusted third parties who may access your data:</p>
<table>
    <thead>
        <tr><th>Service</th><th>Purpose</th><th>Privacy Policy</th></tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>PayPal</strong></td>
            <td>Payment processing</td>
            <td><a href="https://www.paypal.com/webapps/mpp/ua/privacy-full" target="_blank" rel="noopener">paypal.com/privacy</a></td>
        </tr>
        <tr>
            <td><strong>Resend.com</strong></td>
            <td>Email delivery &amp; tracking</td>
            <td><a href="https://resend.com/legal/privacy-policy" target="_blank" rel="noopener">resend.com/legal/privacy-policy</a></td>
        </tr>
        <tr>
            <td><strong>OpenRouter / AI providers</strong></td>
            <td>AI analysis (website URL only; no personal data)</td>
            <td><a href="https://openrouter.ai/privacy" target="_blank" rel="noopener">openrouter.ai/privacy</a></td>
        </tr>
    </tbody>
</table>

<h2>9. Children's Privacy</h2>
<p>Our Service is not intended for individuals under 18. We do not knowingly collect data from children. If you believe we have collected data from a minor, contact us immediately.</p>

<h2>10. Changes to This Policy</h2>
<p>We may update this Privacy Policy periodically. Changes will be posted on this page with a new "Last Updated" date. For material changes affecting your rights, we will notify you via email if you have a recent purchase.</p>

<h2>11. Contact &amp; Complaints</h2>
<p>
    <strong>Audit&amp;Fix</strong><br>
    Email: <a href="mailto:<?= htmlspecialchars(LEGALS_EMAIL) ?>"><?= htmlspecialchars(LEGALS_EMAIL) ?></a><br>
    <?= htmlspecialchars(BUSINESS_ADDRESS) ?>
</p>
<p><strong>Australian Privacy Complaints:</strong> Office of the Australian Information Commissioner (OAIC) — <a href="https://www.oaic.gov.au/" target="_blank" rel="noopener">oaic.gov.au</a> · 1300 363 992</p>
<p><strong>EU/UK Complaints:</strong> You have the right to lodge a complaint with your local Data Protection Authority (DPA).</p>

<h2>12. Compliance</h2>
<p>This Privacy Policy is designed to comply with the <em>Australian Privacy Act 1988</em> (Australian Privacy Principles), GDPR (EU Regulation 2016/679), UK GDPR and Data Protection Act 2018, and the CCPA (where applicable).</p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
