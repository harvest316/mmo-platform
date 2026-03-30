<?php
/**
 * Customer Portal: Login Page
 *
 * Email form → sends magic link. No password.
 * If already logged in, redirects to dashboard.
 */

if (isLoggedIn()) {
    header('Location: /account/dashboard');
    exit;
}

$returnTo = htmlspecialchars($_GET['return'] ?? '/account/dashboard');
?>

<div class="account-card account-card--narrow">
    <div class="account-card__header">
        <h1>Log in to your account</h1>
        <p class="account-card__subtext">
            Enter the email you used to purchase your audit or video review.
            We'll send you a secure login link.
        </p>
    </div>

    <form id="magic-link-form" class="account-form" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="return_to" value="<?= $returnTo ?>">

        <div class="form-group">
            <label for="login-email" class="form-label">Email address</label>
            <input type="email" id="login-email" name="email"
                   class="form-input" required autofocus
                   placeholder="you@yourbusiness.com"
                   autocomplete="email">
        </div>

        <button type="submit" class="btn btn--primary btn--full" id="login-submit">
            Send Login Link
        </button>

        <div id="login-message" class="form-message" hidden></div>
    </form>

    <div id="login-sent" class="account-card__sent" hidden>
        <div class="sent-icon">&#9993;</div>
        <h2>Check your email</h2>
        <p>We've sent a login link to <strong id="sent-email"></strong>.</p>
        <p class="text-muted">The link expires in 30 minutes. Check your spam folder if you don't see it.</p>
        <button type="button" class="btn btn--ghost btn--sm" id="resend-btn" disabled>
            Resend link
        </button>
    </div>
</div>

<script>
(function() {
    var form = document.getElementById('magic-link-form');
    var sentView = document.getElementById('login-sent');
    var sentEmail = document.getElementById('sent-email');
    var submitBtn = document.getElementById('login-submit');
    var message = document.getElementById('login-message');
    var resendBtn = document.getElementById('resend-btn');
    var emailInput = document.getElementById('login-email');
    var lastEmail = '';

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        sendLink();
    });

    resendBtn.addEventListener('click', function() {
        sendLink();
    });

    function sendLink() {
        var email = emailInput.value.trim();
        if (!email) return;

        lastEmail = email;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending...';
        message.hidden = true;

        fetch('/api.php?action=send-magic-link', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': form.querySelector('[name=_csrf]').value
            },
            body: JSON.stringify({ email: email })
        })
        .then(function(r) { return r.json().then(function(d) { return { ok: r.ok, data: d }; }); })
        .then(function(res) {
            if (res.ok && res.data.success) {
                form.hidden = true;
                sentView.hidden = false;
                sentEmail.textContent = email;
                // Enable resend after 60s
                resendBtn.disabled = true;
                var countdown = 60;
                resendBtn.textContent = 'Resend link (' + countdown + 's)';
                var timer = setInterval(function() {
                    countdown--;
                    if (countdown <= 0) {
                        clearInterval(timer);
                        resendBtn.disabled = false;
                        resendBtn.textContent = 'Resend link';
                    } else {
                        resendBtn.textContent = 'Resend link (' + countdown + 's)';
                    }
                }, 1000);
            } else {
                message.textContent = res.data.error || 'Something went wrong. Please try again.';
                message.hidden = false;
                message.className = 'form-message form-message--error';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Send Login Link';
            }
        })
        .catch(function() {
            message.textContent = 'Network error. Please check your connection and try again.';
            message.hidden = false;
            message.className = 'form-message form-message--error';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send Login Link';
        });
    }
})();
</script>
