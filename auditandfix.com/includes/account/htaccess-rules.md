# .htaccess Rules for Customer Portal

Add these rewrite rules to the live `.htaccess` on Hostinger.
Place them BEFORE the existing `RewriteRule ^data/ - [F,L]` line.

```apache
# ── Customer Portal ──────────────────────────────────────────────────────
RewriteRule ^account/?$                          account.php?page=dashboard [L,QSA]
RewriteRule ^account/login/?$                    account.php?page=login     [L,QSA]
RewriteRule ^account/verify/?$                   account.php?page=verify    [L,QSA]
RewriteRule ^account/logout/?$                   account.php?page=logout    [L,QSA]
RewriteRule ^account/(dashboard|billing|reports|subscriptions|videos)/?$ account.php?page=$1 [L,QSA]
RewriteRule ^account/reports/([a-zA-Z0-9]+)/?$   account.php?page=report-viewer&id=$1 [L,QSA]
```

## Also block direct access to account includes:
The existing `RewriteRule ^includes/ - [F,L]` already covers this.

## Also block sessions directory:
Add if not already blocked by the `data/` rule:
```apache
RewriteRule ^data/sessions/ - [F,L]
```
