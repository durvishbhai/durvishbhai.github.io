<?php /** @var string $html pre-built from escaped values only (CmsBlocks) */ /** @var string|null $preview */ ?>
<?php if (!empty($preview)) : ?><div class="alert alert-error" role="status"><p>Preview of a page that is currently <strong><?= e((string) $preview) ?></strong>. Visitors do not see it unless it is published.</p></div><?php endif; ?>
<?= $html ?>
