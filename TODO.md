# TODO

## Blog Post Visual Assets (images, diagrams, charts)

Add visual content to the 3 citation-gap blog posts to improve dwell time and
shareability. Posts currently have no images.

Candidates:
- `why-your-website-isnt-converting.php` — before/after headline examples as
  a simple comparison diagram; a score distribution chart (% of sites failing
  each factor) from the 35,000-site data
- `website-not-getting-enough-enquiries.php` — annotated screenshot of a
  homepage with the 6 problem areas called out
- `small-business-website-audit-checklist.php` — a visual checklist/scorecard
  graphic (pass/fail grid for all 10 factors)

Notes:
- Images should be stored in the website repo's `assets/img/blog/`
- Use `<figure>` + `<figcaption>` for semantic HTML
- Add `ImageObject` to Article schema in `blog/post.php` template once images exist
- Consider generating with an image AI tool (Flux, Midjourney, or Gemini via
  the OpenRouter image gen pattern documented in memory)

---

## Trustpilot Review Data in Schema

Once Trustpilot reviews start coming in for the production site, update the Product
structured data in `index.php` with real `aggregateRating` and
`review` values. Currently a placeholder (1 review, 5/5).

- Trustpilot profile: linked from the production site

- BCC invite email is active: `TRUSTPILOT_BCC_EMAIL` in `333Method/.env`
- Schema location: `index.php` → `@graph` → `Product` → `aggregateRating` + `review`
- Consider: pull rating/count from Trustpilot API automatically, or update manually
  after first 5–10 reviews
