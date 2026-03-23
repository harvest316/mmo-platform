<?php
/**
 * Blog Post Template
 *
 * Copy this file, rename it to your-post-slug.php, and fill in the variables.
 * The filename (minus .php) should match $post_slug.
 * HTML content below the closing ?> tag is rendered as the post body.
 *
 * Files starting with _ are ignored by the blog listing.
 */

$post_title     = 'Post Title Here';
$post_slug      = 'post-slug-here';
$post_date      = '2026-03-23';
$post_excerpt   = 'Short description for listings and meta description. Keep under 160 characters.';
$post_author    = 'Marcus Webb';
$post_read_time = '5 min read';
$post_tags      = ['website-audit', 'small-business'];

// Set to false to hide from listing without deleting
// $post_published = false;
?>

<!-- ── Post content in HTML below ─────────────────────────────────────────── -->

<p>Your opening paragraph goes here. Set the scene and hook the reader.</p>

<h2>First Section Heading</h2>

<p>Section content. Use <code>&lt;h2&gt;</code> for main sections and <code>&lt;h3&gt;</code> for subsections.</p>

<ul>
    <li>Bullet point one</li>
    <li>Bullet point two</li>
    <li>Bullet point three</li>
</ul>

<!-- Mid-article scanner CTA (copy this block wherever you want a CTA) -->
<div class="post-cta">
    <h3>Check your website in 30 seconds</h3>
    <p>Our free scanner grades your site on 10 conversion factors.</p>
    <a href="/scan" class="cta-button">Scan Your Website Free</a>
</div>

<h2>Second Section Heading</h2>

<p>More content here.</p>

<blockquote>
    Pull quotes or key takeaways look great in blockquotes.
</blockquote>

<h3>A Subsection</h3>

<p>Subsection content. Keep paragraphs short and scannable.</p>

<h2>Conclusion</h2>

<p>Wrap up with a clear takeaway and a natural transition to the scanner CTA below.</p>
