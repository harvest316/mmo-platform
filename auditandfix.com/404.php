<?php
/**
 * Branded 404 page
 *
 * Requires .htaccess: ErrorDocument 404 /404.php
 */
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found | Audit&amp;Fix</title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .error-page { max-width: 540px; margin: 6rem auto; padding: 0 1rem; text-align: center; }
        .error-page h1 { font-size: 4rem; color: #2563eb; margin-bottom: 0.5rem; }
        .error-page h2 { font-size: 1.4rem; margin-bottom: 1rem; color: #333; }
        .error-page p { color: #666; line-height: 1.7; margin-bottom: 2rem; }
        .error-page a.back { display: inline-block; background: #2563eb; color: white; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; }
        .error-page a.back:hover { background: #1d4ed8; }
        .error-page .links { margin-top: 2rem; font-size: 0.9rem; }
        .error-page .links a { color: #2563eb; text-decoration: none; margin: 0 0.5rem; }
    </style>
</head>
<body>
    <div class="error-page">
        <h1>404</h1>
        <h2>Page not found</h2>
        <p>The page you're looking for doesn't exist or has been moved.</p>
        <a href="/" class="back">Back to Audit&amp;Fix</a>
        <div class="links">
            <a href="/video-reviews/">Video Reviews</a> &middot;
            <a href="/privacy.php">Privacy</a> &middot;
            <a href="/terms.php">Terms</a>
        </div>
    </div>
</body>
</html>
