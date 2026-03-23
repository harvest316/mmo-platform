# Google Ads -- Measurement Plan

## Conversion Events

| Event | Type | GA4 Event Name | Google Ads Action | Value | Count |
|-------|------|---------------|-------------------|-------|-------|
| Scan Started | Micro | `scan_started` | Secondary | A$0 | Every |
| Scan Completed | Micro | `scan_completed` | Secondary | A$0 | Every |
| Email Captured | Primary Lead | `generate_lead` | **Primary** | A$5 | Every |
| Quick Fixes Purchased | Sale | `purchase` | **Primary** | A$97 | Every |
| Full Audit Purchased | Sale | `purchase` | **Primary** | A$337 | Every |
| Audit+Impl Purchased | Sale | `purchase` | **Primary** | A$625 | Every |

"Primary" conversion actions are what Google Ads optimises toward when using automated bidding. "Secondary" actions are tracked but do not influence bidding.

---

## GA4 Event Configuration

### Event: scan_started

Trigger: User clicks "Score My Website" button
```
gtag('event', 'scan_started', {
  'event_category': 'scanner',
  'event_label': document.querySelector('#scan-url')?.value || ''
});
```

Parameters:
- `event_category`: scanner
- `event_label`: the URL entered

### Event: scan_completed

Trigger: Scan results are displayed (score visible on page)
```
gtag('event', 'scan_completed', {
  'event_category': 'scanner',
  'event_label': scannedUrl,
  'score': scanScore,
  'grade': scanGrade
});
```

Parameters:
- `event_category`: scanner
- `event_label`: scanned URL
- `score`: numeric score (0-100)
- `grade`: letter grade (A-F)

### Event: generate_lead

Trigger: User submits email after scan
```
gtag('event', 'generate_lead', {
  'event_category': 'scanner',
  'currency': 'AUD',
  'value': 5.00
});
```

### Event: purchase

Trigger: Payment confirmed (fire on thank-you/confirmation page)
```
gtag('event', 'purchase', {
  'transaction_id': orderId,
  'currency': 'AUD',
  'value': orderValue,
  'items': [{
    'item_name': productName,
    'price': orderValue,
    'quantity': 1
  }]
});
```

---

## Google Ads Conversion Tracking Setup

### Step 1: Create Conversion Actions in Google Ads

1. Go to Google Ads > Goals > Conversions > New conversion action
2. Select "Website"
3. Create these actions:

**Action 1: Email Captured (Primary)**
- Name: `Email Captured - Scanner`
- Category: Submit lead form
- Value: Use different values for each conversion > Default A$5.00
- Count: Every conversion
- Click-through window: 30 days
- Engaged-view window: 3 days
- Attribution: Data-driven (or Last click if account is too new)

**Action 2: Purchase (Primary)**
- Name: `Purchase`
- Category: Purchase
- Value: Use different values for each conversion > no default (pass dynamically)
- Count: Every conversion
- Click-through window: 90 days
- Engaged-view window: 3 days
- Attribution: Data-driven

**Action 3: Scan Started (Secondary)**
- Name: `Scan Started`
- Category: Other
- Value: Don't use a value
- Count: Every conversion
- Mark as SECONDARY (do not include in conversions column)

**Action 4: Scan Completed (Secondary)**
- Name: `Scan Completed`
- Category: Other
- Value: Don't use a value
- Count: Every conversion
- Mark as SECONDARY

### Step 2: Get the Google Ads Tag

After creating conversion actions, Google provides a global site tag and event snippets.

The global site tag (gtag.js) looks like:
```html
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=AW-XXXXXXXXXX"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'AW-XXXXXXXXXX');
</script>
```

Place this in the `<head>` of every page on auditandfix.com.

### Step 3: Add Event Snippets

For each conversion action, Google provides a conversion label. Fire these when the event occurs:

```javascript
// Email Captured
gtag('event', 'conversion', {
  'send_to': 'AW-XXXXXXXXXX/LABEL_EMAIL',
  'value': 5.00,
  'currency': 'AUD'
});

// Purchase
gtag('event', 'conversion', {
  'send_to': 'AW-XXXXXXXXXX/LABEL_PURCHASE',
  'value': orderValue,
  'currency': 'AUD',
  'transaction_id': orderId
});

// Scan Started
gtag('event', 'conversion', {
  'send_to': 'AW-XXXXXXXXXX/LABEL_SCAN_START'
});

// Scan Completed
gtag('event', 'conversion', {
  'send_to': 'AW-XXXXXXXXXX/LABEL_SCAN_COMPLETE'
});
```

Replace `AW-XXXXXXXXXX` and `LABEL_*` with actual values from your Google Ads account.

---

## GTM Alternative (Optional)

If you prefer Google Tag Manager over direct gtag.js placement:

### Step 1: Create GTM Container

1. Go to tagmanager.google.com > Create Account
2. Container name: auditandfix.com
3. Platform: Web
4. Install the GTM snippet on all pages

### Step 2: Tags to Create

| Tag Name | Tag Type | Trigger |
|----------|----------|---------|
| GA4 - Config | GA4 Configuration | All Pages |
| GA4 - Scan Started | GA4 Event (scan_started) | Custom Event: scan_started |
| GA4 - Scan Completed | GA4 Event (scan_completed) | Custom Event: scan_completed |
| GA4 - Email Captured | GA4 Event (generate_lead) | Custom Event: email_captured |
| GA4 - Purchase | GA4 Event (purchase) | Custom Event: purchase_complete |
| Google Ads - Email Captured | Google Ads Conversion | Custom Event: email_captured |
| Google Ads - Purchase | Google Ads Conversion | Custom Event: purchase_complete |

### Step 3: Push Data Layer Events

In your website code, push events to the data layer instead of calling gtag directly:

```javascript
// Scan started
window.dataLayer.push({
  'event': 'scan_started',
  'scan_url': enteredUrl
});

// Scan completed
window.dataLayer.push({
  'event': 'scan_completed',
  'scan_url': scannedUrl,
  'scan_score': score,
  'scan_grade': grade
});

// Email captured
window.dataLayer.push({
  'event': 'email_captured'
});

// Purchase
window.dataLayer.push({
  'event': 'purchase_complete',
  'transaction_id': orderId,
  'value': orderValue,
  'currency': 'AUD',
  'product_name': productName
});
```

### Step 4: Create Variables in GTM

| Variable Name | Type | Data Layer Variable Name |
|---------------|------|-------------------------|
| DLV - Scan URL | Data Layer Variable | scan_url |
| DLV - Scan Score | Data Layer Variable | scan_score |
| DLV - Scan Grade | Data Layer Variable | scan_grade |
| DLV - Transaction ID | Data Layer Variable | transaction_id |
| DLV - Value | Data Layer Variable | value |
| DLV - Currency | Data Layer Variable | currency |
| DLV - Product Name | Data Layer Variable | product_name |

---

## Recommendation

Use direct gtag.js for now. GTM adds complexity that is not justified for a single website with 4 conversion events. You can migrate to GTM later if you add more sites or tracking requirements.

The key tracking priority order:
1. **Email Captured** -- this is your primary lead metric and what you will optimise toward
2. **Purchase** -- revenue tracking, essential for ROAS calculation
3. **Scan Completed** -- funnel health metric
4. **Scan Started** -- click-to-scan rate diagnostic

---

## Linking Google Ads to GA4

1. In GA4: Admin > Google Ads Links > Link
2. Select your Google Ads account
3. Enable auto-tagging (should be on by default)
4. This allows you to see Google Ads data in GA4 reports and import GA4 conversions into Google Ads

## Verification Checklist

After setup, verify each event fires correctly:

- [ ] Open auditandfix.com/scan in Chrome
- [ ] Open Chrome DevTools > Network tab, filter by "google" or "collect"
- [ ] Enter a URL and click scan -- verify `scan_started` fires
- [ ] Wait for results -- verify `scan_completed` fires
- [ ] Submit email -- verify `generate_lead` fires
- [ ] Complete a test purchase -- verify `purchase` fires
- [ ] Check GA4 Realtime report -- all events should appear
- [ ] Check Google Ads > Conversions -- status should show "Recording conversions" within 24-48 hours
