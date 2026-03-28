# Hero Image Generation Prompts

These prompts are ready for AI image generation (Flux, DALL-E 3, Midjourney, etc.).
Target: 1920x1080 minimum, WebP/AVIF preferred, fallback PNG.

## scan.php — Main Hero (replaces hero-background.png)

**Prompt:** A professional, modern workspace scene with a large desktop monitor displaying website analytics dashboards and performance metrics. Clean, minimal desk setup with soft blue ambient lighting. The screen shows bar charts, speed gauges, and SEO scores in blues and greens. Shallow depth of field, the background softly blurred. Corporate tech aesthetic, photorealistic, editorial quality. No text, no logos, no watermarks. Aspect ratio 16:9.

**Usage:** `.hero` background in `style.css` — overlaid with navy-to-transparent gradient (left 96% opacity to right 25%), so the right side of the image is most visible.

**Sizing note:** Must look good cropped to center on mobile (only ~30% of image visible).

## video-reviews/ — Hero

**Prompt:** A smartphone in hand showing a 5-star Google review with a video play button overlay. Modern cafe or retail store interior in soft focus behind. Warm, inviting lighting. Professional photography style, shallow depth of field. No readable text on the phone (blurred), just the visual suggestion of reviews and video. Aspect ratio 16:9.

**Usage:** `.vr-hero` background — similar gradient overlay treatment.

## compare pages — Hero

**Prompt:** Split-screen concept: left side shows a dated, static website testimonials page (muted, desaturated). Right side shows a modern video review widget with a play button and star ratings (vibrant, saturated). Clean dividing line between old and new. Professional tech comparison visual. No text. Aspect ratio 16:9.

**Usage:** `.cmp-hero` background — gradient overlay from left.

## blog/ — Hero

**Prompt:** Overhead flat-lay of a content marketer's desk: laptop showing blog analytics, notebook with handwritten notes, coffee cup, succulent plant, phone with social media notifications. Clean white desk, natural daylight, editorial photography style. No readable text. Aspect ratio 16:9.

## General Guidelines

- All images should feel cohesive (similar lighting, color temperature, quality level)
- Primary brand colors: navy (#1a365d), teal (#0d9488), white
- Avoid: stock photo cliches (handshakes, pointing at screens, generic office)
- Prefer: authentic, editorial-quality scenes
- Output: generate at 2x (3840x2160) then downscale for retina support
- Compress: target <200KB for hero images (use squoosh.app or similar)
