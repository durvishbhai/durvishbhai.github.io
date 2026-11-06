<?php /** @var string $kind */ /** @var array<string,mixed> $r */ ?>
<section class="section"><div class="container narrow">
<div class="panel"><h1>Database restored</h1>
    <p>The backup was restored: <strong><?= e((string) $r['tables']) ?></strong> tables from <strong><?= e((string) $r['statements']) ?></strong> statements.</p>
    <?php if ($r['migrations'] !== []) : ?><p>The backup was older than the program, so these database updates were applied: <?= e(implode(', ', $r['migrations'])) ?>.</p><?php endif; ?>
    <p>A safety copy of the data from just before the restore was saved as <code><?= e((string) $r['safety_copy']) ?></code> in <code>vertex/storage/backups</code>.</p>
    <p>You have been signed out. Sign in again with an account that exists in the backup.</p>
    <p><a class="btn btn-primary" href="/login">Sign in</a></p></div>
</div></section>
