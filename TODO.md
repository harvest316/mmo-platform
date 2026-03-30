# TODO

## Trustpilot Review Data in Schema

Once Trustpilot reviews start coming in for auditandfix.com, update the Product
structured data in `auditandfix.com/index.php` with real `aggregateRating` and
`review` values. Currently a placeholder (1 review, 5/5).

- Trustpilot profile: https://au.trustpilot.com/review/auditandfix.com
- BCC invite email is active: `TRUSTPILOT_BCC_EMAIL` in `333Method/.env`
- Schema location: `index.php` → `@graph` → `Product` → `aggregateRating` + `review`
- Consider: pull rating/count from Trustpilot API automatically, or update manually
  after first 5–10 reviews
