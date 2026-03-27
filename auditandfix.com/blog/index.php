<?php
/**
 * Blog listing page — /blog/
 *
 * Lists all published blog posts from blog/posts/ directory.
 * Each post file defines frontmatter-like PHP variables.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/geo.php';

$countryCode = detectCountry();

// ── Load all posts ──────────────────────────────────────────────────────────

function loadAllPosts(): array {
    $postsDir = __DIR__ . '/posts';
    $posts = [];

    if (!is_dir($postsDir)) return $posts;

    foreach (glob($postsDir . '/*.php') as $file) {
        $basename = basename($file, '.php');
        // Skip template and hidden files
        if ($basename === '_template' || strpos($basename, '_') === 0) continue;

        // Extract post variables without executing in global scope
        unset($post_title, $post_slug, $post_date, $post_excerpt, $post_author, $post_read_time, $post_tags, $post_published);
        ob_start();
        include $file;
        ob_end_clean();

        // Skip unpublished posts
        if (isset($post_published) && $post_published === false) continue;

        // Skip posts without required fields
        if (empty($post_title) || empty($post_slug) || empty($post_date)) continue;

        $posts[] = [
            'title'     => $post_title,
            'slug'      => $post_slug,
            'date'      => $post_date,
            'excerpt'   => $post_excerpt ?? '',
            'author'    => $post_author ?? 'Marcus Webb',
            'read_time' => $post_read_time ?? '5 min read',
            'tags'      => $post_tags ?? [],
            'file'      => $file,
        ];
    }

    // Sort by date descending (newest first)
    usort($posts, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    return $posts;
}

$posts = loadAllPosts();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Conversion Tips for Small Business | Audit&amp;Fix Blog</title>
    <meta name="description" content="Practical tips to get more leads from your website. Conversion advice, audit checklists, and data-backed insights for tradies and local service businesses.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.auditandfix.com/blog/">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.auditandfix.com/blog/">
    <meta property="og:title" content="Website Conversion Tips for Small Business | Audit&amp;Fix Blog">
    <meta property="og:description" content="Practical tips to get more leads from your website. Conversion advice, audit checklists, and insights for local businesses.">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&amp;Fix">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">

    <!-- Schema.org BreadcrumbList -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        "itemListElement": [
            { "@type": "ListItem", "position": 1, "name": "Home", "item": "https://www.auditandfix.com/" },
            { "@type": "ListItem", "position": 2, "name": "Blog", "item": "https://www.auditandfix.com/blog/" }
        ]
    }
    </script>

    <style>
        .blog-header {
            background: linear-gradient(135deg, var(--color-navy) 0%, var(--color-navy-mid) 100%);
            color: #fff;
            padding: calc(76px + 3rem) 1rem 2.5rem;
            margin-top: -76px;
            text-align: center;
        }
        .blog-header h1 {
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
            font-weight: 800;
        }
        .blog-header p {
            opacity: 0.85;
            font-size: 1.05rem;
            max-width: 560px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* Breadcrumbs */
        .breadcrumbs {
            max-width: 900px;
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

        /* Post listing */
        .blog-listing {
            max-width: 900px;
            margin: 0 auto;
            padding: 1rem 24px 4rem;
        }
        .post-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        .post-card-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .post-card {
            background: #fff;
            border: 1px solid var(--color-border);
            border-radius: 10px;
            padding: 1.75rem;
            transition: box-shadow 0.2s, transform 0.15s;
            cursor: pointer;
        }
        .post-card-link:hover .post-card {
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }
        .post-card-meta {
            font-size: 0.82rem;
            color: var(--color-text-muted);
            margin-bottom: 0.75rem;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .post-card h2 {
            font-size: 1.15rem;
            color: var(--color-navy);
            margin-bottom: 0.5rem;
            line-height: 1.4;
        }
        .post-card .excerpt {
            color: var(--color-text-mid);
            font-size: 0.92rem;
            line-height: 1.6;
        }

        /* Empty state */
        .blog-empty {
            text-align: center;
            padding: 4rem 1rem;
            color: var(--color-text-muted);
        }
        .blog-empty h2 {
            color: var(--color-navy);
            margin-bottom: 1rem;
        }

        /* Scanner CTA */
        .blog-cta {
            max-width: 700px;
            margin: 0 auto 3rem;
            padding: 2.5rem;
            background: linear-gradient(135deg, var(--color-navy) 0%, var(--color-navy-mid) 100%);
            border-radius: 12px;
            text-align: center;
            color: #fff;
        }
        .blog-cta h2 {
            font-size: 1.4rem;
            margin-bottom: 0.75rem;
        }
        .blog-cta p {
            opacity: 0.85;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }

    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/consent-banner.php'; ?>
<?php require_once __DIR__ . '/../includes/header.php'; ?>

<header class="blog-header">
    <h1>The Audit&amp;Fix Blog</h1>
    <p>Practical tips to get more enquiries from your website. No jargon, no fluff — just what works.</p>
</header>

<nav class="breadcrumbs" aria-label="Breadcrumb">
    <a href="/">Home</a><span class="sep">/</span>
    <span>Blog</span>
</nav>

<main class="blog-listing">
    <?php if (empty($posts)): ?>
    <div class="blog-empty">
        <h2>Coming soon</h2>
        <p>We're working on our first articles. In the meantime, try our free website scanner to see how your site scores.</p>
        <a href="/scan" class="cta-button" style="margin-top: 1.5rem;">Scan Your Website Free</a>
    </div>
    <?php else: ?>
    <div class="post-cards">
        <?php foreach ($posts as $post): ?>
        <a href="/blog/<?= htmlspecialchars($post['slug']) ?>" class="post-card-link">
        <article class="post-card">
            <div class="post-card-meta">
                <time datetime="<?= htmlspecialchars($post['date']) ?>"><?= date('j M Y', strtotime($post['date'])) ?></time>
                <span><?= htmlspecialchars($post['read_time']) ?></span>
            </div>
            <h2><?= htmlspecialchars($post['title']) ?></h2>
            <p class="excerpt"><?= htmlspecialchars($post['excerpt']) ?></p>
        </article>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<!-- Scanner CTA -->
<section style="max-width: 700px; margin: 0 auto 3rem; padding: 0 24px;">
    <div class="blog-cta">
        <h2>Not sure how your website stacks up?</h2>
        <p>Our free scanner checks 10 conversion factors and gives you a score in 30 seconds.</p>
        <a href="/scan" class="cta-button">Scan Your Website Free</a>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

</body>
</html>
