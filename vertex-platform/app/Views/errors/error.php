<?php
/** @var int $status */
/** @var string $message */
/** @var string $reference */
/** @var string|null $trace */
$noindex = true;
$status = (int) $status;
$signedIn = \Vertex\Services\Auth::user() !== null;
// Friendly headline and explanation by status. The thrown message is shown too when it says something more specific.
$pages = [
    400 => ['Bad request', 'We could not understand that request. Please go back and try again.'],
    401 => ['Please sign in', 'You need to sign in to see this page.'],
    403 => ['Access denied', 'You do not have permission to open this page. If you think this is a mistake, please contact our team.'],
    404 => ['Page not found', 'The page you are looking for may have moved, been renamed, or may not be published yet.'],
    405 => ['That action is not allowed here', 'This address cannot be opened that way. Please go back and use the buttons on the page.'],
    413 => ['That file is too large', 'The file you sent is bigger than we accept. Please send a smaller file.'],
    419 => ['Your session expired', 'The page was open for too long. Go back, refresh it and try again.'],
    429 => ['Too many attempts', 'Please wait a few minutes before trying again.'],
    500 => ['Something went wrong', 'An unexpected error occurred on our side. Our team has been notified. Please try again in a moment.'],
    502 => ['Service temporarily unavailable', 'We could not reach part of our system. Please try again in a moment.'],
    503 => ['Service temporarily unavailable', 'We are briefly unavailable. Please try again in a few minutes.'],
    504 => ['The server took too long', 'The request took too long to finish. Please try again in a moment.'],
];
[$headline, $text] = $pages[$status] ?? ($status >= 500 ? $pages[500] : ['Something went wrong', 'The request could not be completed.']);
$generic = \Vertex\Core\HttpException::defaultMessage($status);
$specific = $message !== '' && $message !== $generic && $message !== 'Something went wrong' && !str_starts_with($message, 'An unexpected error occurred') && mb_strlen($message) < 200 ? $message : '';
$icon = $status === 404 ? '?' : ($status === 403 || $status === 401 ? '🔒' : ($status === 429 || $status === 419 ? '⏳' : '!'));
?>
<section class="auth error-page">
    <div class="auth-card">
        <p class="error-code" aria-hidden="true"><?= e((string) $status) ?></p>
        <p class="eyebrow">Error <?= e((string) $status) ?></p>
        <h1><?= e($headline) ?></h1>
        <p class="muted"><?= e($text) ?></p>
        <?php if ($specific !== '') : ?><p class="muted"><?= e($specific) ?></p><?php endif; ?>
        <?php if ($status === 404) : ?><p class="chip-row"><a class="pub-chip" href="/services">Services</a><a class="pub-chip" href="/contact">Contact us</a><a class="pub-chip" href="/faq">FAQ</a><a class="pub-chip" href="/sitemap">Sitemap</a></p><?php endif; ?>
        <p class="error-actions">
            <a class="btn btn-primary" href="<?= $signedIn ? '/dashboard' : '/' ?>"><?= $signedIn ? 'Go to my dashboard' : 'Back to home' ?></a>
            <?php if (in_array($status, [401, 403, 419], true) && !$signedIn) : ?><a class="btn btn-ghost" href="/login">Sign in</a><?php endif; ?>
            <?php if ($status >= 500 || $status === 429) : ?><a class="btn btn-ghost" href="">Try again</a><?php endif; ?>
            <?php if ($status !== 404 && $status < 500) : ?><a class="btn btn-ghost" href="/contact">Contact us</a><?php endif; ?>
        </p>
        <p class="muted small">Reference: <code><?= e($reference) ?></code> <span aria-hidden="true">·</span> quote this if you contact us.</p>
        <?php if (!empty($trace)) : ?>
            <pre class="trace"><?= e($trace) ?></pre>
        <?php endif; ?>
    </div>
</section>
