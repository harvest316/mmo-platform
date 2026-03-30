<?php
/**
 * Customer Portal: Logout
 *
 * Destroys session and redirects to homepage.
 */

destroySession();
header('Location: /');
exit;
