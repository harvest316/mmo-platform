<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/geo.php';

// Impressum is only required in DACH region (DE/AT/CH per §5 DDG).
// Redirect non-DACH visitors to the Terms of Service page.
$countryCode = detectCountry();
$lang = detectLang();
if (!in_array($countryCode, ['DE', 'AT', 'CH'], true) && $lang !== 'de') {
    header('Location: terms.php', true, 302);
    exit;
}

$pageTitle = 'Impressum — Audit&Fix';
$pageDescription = 'Legal disclosure / Impressum for Audit&Fix (auditandfix.com).';
$pageLang = 'de';
require_once __DIR__ . '/includes/legal-header.php';
?>

<h1>Impressum</h1>
<p class="legal-meta">Pflichtangaben gemäß § 5 DDG (DE) / § 25 MedienG (AT) / Art. 3 UWG (CH)</p>

<p>This Impressum / Legal Disclosure is provided to comply with the legal information obligations applicable in Germany (DDG, effective May 2024), Austria (MedienG), and Switzerland (UWG).</p>

<h2>Angaben gemäß § 5 DDG &mdash; Information according to § 5 DDG</h2>

<p>
    <strong>Audit&amp;Fix</strong><br>
    Sole Trader / Einzelunternehmer<br>
    <?= htmlspecialchars(BUSINESS_ADDRESS) ?><br>
    Email: <a href="mailto:<?= htmlspecialchars(LEGALS_EMAIL) ?>"><?= htmlspecialchars(LEGALS_EMAIL) ?></a><br>
    Phone: <?= htmlspecialchars(BUSINESS_PHONE) ?><br>
    Website: <a href="https://www.auditandfix.com">www.auditandfix.com</a>
</p>

<h2>Verantwortliche Person &mdash; Responsible Person</h2>
<p>
    <?= htmlspecialchars(OPERATOR_NAME) ?><br>
    Audit&amp;Fix<br>
    <?= htmlspecialchars(BUSINESS_ADDRESS) ?>
</p>

<h2>Steuerliche Angaben &mdash; Tax Information</h2>
<p>Audit&amp;Fix is an Australian sole trader business. Australian Business Number (ABN): <?= htmlspecialchars(BUSINESS_ABN) ?>. We are not registered for VAT in the European Union or United Kingdom, and hold no EU VAT identification number (USt-IdNr / UID-Nummer).</p>

<h2>Streitschlichtung &mdash; Dispute Resolution</h2>
<p>The European Commission provides an online dispute resolution platform: <a href="https://ec.europa.eu/consumers/odr/" target="_blank" rel="noopener">https://ec.europa.eu/consumers/odr/</a></p>
<p>We are not obliged to participate in dispute resolution proceedings before a consumer arbitration board, but we are willing to attempt an amicable resolution. Please contact us first at <a href="mailto:reports@auditandfix.com">reports@auditandfix.com</a>.</p>

<h2>Haftungsausschluss &mdash; Disclaimer</h2>

<h3>Haftung für Inhalte &mdash; Liability for Content</h3>
<p>As a service provider, we are responsible for our own content on these pages in accordance with general legislation. We are not obliged to monitor third-party information transmitted or stored, or to investigate circumstances that indicate illegal activity.</p>

<h3>Haftung für Links &mdash; Liability for Links</h3>
<p>Our website contains links to external third-party websites over which we have no control. We cannot accept any liability for the content of these external websites. The respective provider or operator of the linked pages is always responsible for their content.</p>

<h3>Urheberrecht &mdash; Copyright</h3>
<p>The content and works created by the site operators on these pages are subject to Australian copyright law. Duplication, processing, distribution, or any form of commercialisation of such material beyond the scope of copyright law requires the prior written consent of Audit&amp;Fix.</p>

<h2>Contact</h2>
<p>Email: <a href="mailto:<?= htmlspecialchars(LEGALS_EMAIL) ?>"><?= htmlspecialchars(LEGALS_EMAIL) ?></a></p>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
