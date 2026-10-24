<?php /** @var array<string,mixed> $p */ ?>
<section class="pub-page-head"><div class="container narrow"><p class="eyebrow">Legal</p><h1><?= e($p['title']) ?></h1>
    <p class="muted"><?php if ($p['effective_date']) : ?>Effective <?= e(local_date((string) $p['effective_date'])) ?> · <?php endif; ?>Last updated <?= e(local_date(substr((string) $p['updated_at'], 0, 10))) ?></p></div></section>
<section class="pub-section"><div class="container narrow"><div class="prose policy"><?= sr((string) $p['body']) ?></div></div></section>
