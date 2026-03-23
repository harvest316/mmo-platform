<?php
/**
 * Blog post template — /blog/{slug}
 *
 * Loads a post from blog/posts/{slug}.php
 * Renders with semantic HTML, structured data, author bio, and scanner CTAs.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/geo.php';

$countryCode = detectCountry();

// ── Load the requested post ─────────────────────────────────────────────────

$slug = preg_replace('/[^a-z0-9-]/', '', $_GET['slug'] ?? '');
$postFile = __DIR__ . '/posts/' . $slug . '.php';

if (!$slug || !is_file($postFile) || strpos(basename($postFile), '_') === 0) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

// Extract post variables
unset($post_title, $post_slug, $post_date, $post_excerpt, $post_author, $post_read_time, $post_tags, $post_published);

// Capture post content (HTML below the PHP frontmatter)
ob_start();
include $postFile;
$post_content = ob_get_clean();

// Check for unpublished
if (isset($post_published) && $post_published === false) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

// Defaults
$post_title     = $post_title     ?? 'Untitled';
$post_slug      = $post_slug      ?? $slug;
$post_date      = $post_date      ?? date('Y-m-d');
$post_excerpt   = $post_excerpt   ?? '';
$post_author    = $post_author    ?? 'Marcus Webb';
$post_read_time = $post_read_time ?? '5 min read';
$post_tags      = $post_tags      ?? [];

$post_title_escaped   = htmlspecialchars($post_title, ENT_QUOTES);
$post_excerpt_escaped = htmlspecialchars($post_excerpt, ENT_QUOTES);
$post_date_formatted  = date('j M Y', strtotime($post_date));
$post_url             = 'https://www.auditandfix.com/blog/' . htmlspecialchars($post_slug);

// ── Load related posts ──────────────────────────────────────────────────────

function loadRelatedPosts(string $currentSlug, array $currentTags, int $limit = 3): array {
    $postsDir = __DIR__ . '/posts';
    $related = [];

    if (!is_dir($postsDir)) return $related;

    foreach (glob($postsDir . '/*.php') as $file) {
        $basename = basename($file, '.php');
        if ($basename === '_template' || strpos($basename, '_') === 0) continue;
        if ($basename === $currentSlug) continue;

        unset($post_title, $post_slug, $post_date, $post_excerpt, $post_author, $post_read_time, $post_tags, $post_published);
        include $file;

        if (isset($post_published) && $post_published === false) continue;
        if (empty($post_title) || empty($post_slug) || empty($post_date)) continue;

        // Score by tag overlap
        $tags = $post_tags ?? [];
        $overlap = count(array_intersect($tags, $currentTags));

        $related[] = [
            'title'     => $post_title,
            'slug'      => $post_slug,
            'date'      => $post_date,
            'excerpt'   => $post_excerpt ?? '',
            'read_time' => $post_read_time ?? '5 min read',
            'overlap'   => $overlap,
        ];
    }

    // Sort by tag overlap (desc), then date (desc)
    usort($related, function($a, $b) {
        if ($a['overlap'] !== $b['overlap']) return $b['overlap'] - $a['overlap'];
        return strtotime($b['date']) - strtotime($a['date']);
    });

    return array_slice($related, 0, $limit);
}

$relatedPosts = loadRelatedPosts($slug, $post_tags);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $post_title_escaped ?> | Audit&amp;Fix Blog</title>
    <meta name="description" content="<?= $post_excerpt_escaped ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= $post_url ?>">

    <!-- Open Graph -->
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?= $post_url ?>">
    <meta property="og:title" content="<?= $post_title_escaped ?>">
    <meta property="og:description" content="<?= $post_excerpt_escaped ?>">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&amp;Fix">
    <meta property="article:published_time" content="<?= htmlspecialchars($post_date) ?>">
    <meta property="article:author" content="<?= htmlspecialchars($post_author) ?>">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">

    <!-- Schema.org Article + BreadcrumbList -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "Article",
                "headline": <?= json_encode($post_title) ?>,
                "description": <?= json_encode($post_excerpt) ?>,
                "datePublished": <?= json_encode($post_date) ?>,
                "dateModified": <?= json_encode($post_date) ?>,
                "author": {
                    "@type": "Person",
                    "name": <?= json_encode($post_author) ?>,
                    "jobTitle": "CRO Specialist",
                    "url": "https://www.auditandfix.com/"
                },
                "publisher": {
                    "@type": "Organization",
                    "@id": "https://www.auditandfix.com/#organization",
                    "name": "Audit&Fix",
                    "logo": {
                        "@type": "ImageObject",
                        "url": "https://www.auditandfix.com/assets/img/logo.svg"
                    }
                },
                "mainEntityOfPage": {
                    "@type": "WebPage",
                    "@id": <?= json_encode($post_url) ?>
                },
                "image": "https://www.auditandfix.com/assets/img/og-image.png"
            },
            {
                "@type": "BreadcrumbList",
                "itemListElement": [
                    { "@type": "ListItem", "position": 1, "name": "Home", "item": "https://www.auditandfix.com/" },
                    { "@type": "ListItem", "position": 2, "name": "Blog", "item": "https://www.auditandfix.com/blog/" },
                    { "@type": "ListItem", "position": 3, "name": <?= json_encode($post_title) ?>, "item": <?= json_encode($post_url) ?> }
                ]
            }
        ]
    }
    </script>

    <style>
        /* Nav */
        .post-nav {
            background: var(--color-navy-deep);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 24px;
        }
        .post-nav .logo-text {
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            text-decoration: none;
        }
        .post-nav .logo-amp { color: var(--color-orange); }
        .post-nav-links { display: flex; gap: 16px; align-items: center; }
        .post-nav-links a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-size: 0.88rem;
        }
        .post-nav-links a:hover { color: #fff; }

        /* Breadcrumbs */
        .breadcrumbs {
            max-width: 740px;
            margin: 0 auto;
            padding: 1rem 24px;
            font-size: 0.85rem;
            color: var(--color-text-muted);
        }
        .breadcrumbs a {
            color: var(--color-link);
            text-decoration: none;
        }
        .breadcrumbs a:hover { text-decoration: underline; }
        .breadcrumbs .sep { margin: 0 6px; color: var(--color-text-faint); }

        /* Article */
        .post-article {
            max-width: 740px;
            margin: 0 auto;
            padding: 0 24px 3rem;
        }
        .post-article header {
            padding: 1.5rem 0 2rem;
            border-bottom: 1px solid var(--color-border);
            margin-bottom: 2rem;
        }
        .post-article h1 {
            font-size: 2rem;
            line-height: 1.3;
            color: var(--color-navy);
            margin-bottom: 1rem;
            font-weight: 800;
        }
        .post-meta {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 0.88rem;
            color: var(--color-text-muted);
        }
        .post-meta .author { font-weight: 600; color: var(--color-text-mid); }

        /* Post content */
        .post-body {
            font-size: 1.02rem;
            line-height: 1.8;
            color: var(--color-text-dark);
        }
        .post-body h2 {
            font-size: 1.5rem;
            color: var(--color-navy);
            margin: 2.5rem 0 1rem;
            font-weight: 700;
        }
        .post-body h3 {
            font-size: 1.2rem;
            color: var(--color-navy);
            margin: 2rem 0 0.75rem;
            font-weight: 600;
        }
        .post-body p { margin-bottom: 1.25rem; }
        .post-body ul, .post-body ol {
            margin-bottom: 1.25rem;
            padding-left: 1.5rem;
        }
        .post-body li {
            margin-bottom: 0.5rem;
            line-height: 1.7;
        }
        .post-body blockquote {
            border-left: 4px solid var(--color-orange);
            padding: 1rem 1.25rem;
            margin: 1.5rem 0;
            background: var(--color-surface-alt);
            color: var(--color-text-mid);
            font-style: italic;
            border-radius: 0 6px 6px 0;
        }
        .post-body img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            margin: 1.5rem 0;
        }
        .post-body a { color: var(--color-link); }
        .post-body a:hover { text-decoration: underline; }

        /* Mid-article CTA */
        .post-cta {
            margin: 2.5rem 0;
            padding: 2rem;
            background: linear-gradient(135deg, var(--color-navy) 0%, var(--color-navy-mid) 100%);
            border-radius: 10px;
            text-align: center;
            color: #fff;
        }
        .post-cta h3 {
            color: #fff;
            font-size: 1.2rem;
            margin: 0 0 0.5rem;
        }
        .post-cta p {
            opacity: 0.85;
            margin-bottom: 1rem;
            font-size: 0.92rem;
        }

        /* Tags */
        .post-tags {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 2rem 0;
            padding-top: 1.5rem;
            border-top: 1px solid var(--color-border);
        }
        .post-tag {
            background: var(--color-surface-alt);
            color: var(--color-text-mid);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.82rem;
            text-decoration: none;
        }

        /* Author bio */
        .author-bio {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            margin: 2.5rem 0;
            padding: 2rem;
            background: var(--color-surface-alt);
            border-radius: 10px;
            border-left: 4px solid var(--color-orange);
        }
        .author-bio-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: var(--color-navy);
            color: #fff;
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 72px;
            text-align: center;
            flex-shrink: 0;
        }
        .author-bio-text .author-name {
            font-weight: 700;
            color: var(--color-navy);
            font-size: 1rem;
            margin-bottom: 2px;
        }
        .author-bio-text .author-title {
            font-size: 0.85rem;
            color: var(--color-text-muted);
            font-style: italic;
            margin-bottom: 8px;
        }
        .author-bio-text p {
            font-size: 0.9rem;
            color: var(--color-text-mid);
            line-height: 1.6;
            margin: 0;
        }

        /* Related posts */
        .related-posts {
            margin: 3rem 0 0;
            padding-top: 2rem;
            border-top: 1px solid var(--color-border);
        }
        .related-posts h2 {
            font-size: 1.3rem;
            color: var(--color-navy);
            margin-bottom: 1.25rem;
        }
        .related-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1rem;
        }
        .related-card {
            background: #fff;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 1.25rem;
            transition: box-shadow 0.2s;
        }
        .related-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        .related-card h3 {
            font-size: 0.95rem;
            color: var(--color-navy);
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }
        .related-card h3 a {
            color: inherit;
            text-decoration: none;
        }
        .related-card h3 a:hover { color: var(--color-orange); }
        .related-card .meta {
            font-size: 0.78rem;
            color: var(--color-text-muted);
        }

        @media (max-width: 600px) {
            .post-article h1 { font-size: 1.6rem; }
            .author-bio { flex-direction: column; align-items: center; text-align: center; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/consent-banner.php'; ?>

<nav class="post-nav">
    <a href="/" class="logo-text">Audit<span class="logo-amp">&amp;</span>Fix</a>
    <div class="post-nav-links">
        <a href="/">Home</a>
        <a href="/blog/">Blog</a>
        <a href="/scan">Free Scanner</a>
    </div>
</nav>

<nav class="breadcrumbs" aria-label="Breadcrumb">
    <a href="/">Home</a><span class="sep">/</span>
    <a href="/blog/">Blog</a><span class="sep">/</span>
    <span><?= $post_title_escaped ?></span>
</nav>

<main class="post-article">
    <article>
        <header>
            <h1><?= $post_title_escaped ?></h1>
            <div class="post-meta">
                <span class="author"><?= htmlspecialchars($post_author) ?></span>
                <time datetime="<?= htmlspecialchars($post_date) ?>"><?= $post_date_formatted ?></time>
                <span><?= htmlspecialchars($post_read_time) ?></span>
            </div>
        </header>

        <div class="post-body">
            <?= $post_content ?>
        </div>

        <?php if (!empty($post_tags)): ?>
        <div class="post-tags">
            <?php foreach ($post_tags as $tag): ?>
            <span class="post-tag"><?= htmlspecialchars($tag) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </article>

    <!-- Author Bio -->
    <aside class="author-bio">
        <div class="author-bio-avatar">MW</div>
        <div class="author-bio-text">
            <div class="author-name">Marcus Webb</div>
            <div class="author-title">CRO Specialist, Audit&amp;Fix</div>
            <p>Marcus has analysed over 23,000 small business websites to understand what converts visitors into customers. He writes plain-English guides for business owners who want more enquiries without the jargon.</p>
        </div>
    </aside>

    <!-- Bottom CTA -->
    <div class="post-cta">
        <h3>Want to know how your website scores?</h3>
        <p>Our free scanner checks 10 conversion factors and gives you a grade in 30 seconds.</p>
        <a href="/scan" class="cta-button">Scan Your Website Free</a>
    </div>

    <!-- Related Posts -->
    <?php if (!empty($relatedPosts)): ?>
    <section class="related-posts">
        <h2>Related articles</h2>
        <div class="related-grid">
            <?php foreach ($relatedPosts as $rp): ?>
            <div class="related-card">
                <h3><a href="/blog/<?= htmlspecialchars($rp['slug']) ?>"><?= htmlspecialchars($rp['title']) ?></a></h3>
                <div class="meta">
                    <time datetime="<?= htmlspecialchars($rp['date']) ?>"><?= date('j M Y', strtotime($rp['date'])) ?></time>
                    &middot; <?= htmlspecialchars($rp['read_time']) ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>
