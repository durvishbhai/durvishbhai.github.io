<?php
/** @var int $page */
/** @var int $perPage */
/** @var int $total */
/** @var array<string,string> $filters */
$pages = max(1, (int) ceil($total / $perPage));
if ($pages <= 1) {
    return;
}
$link = static function (int $n) use ($filters): string {
    return '?' . http_build_query(array_filter($filters, static fn ($v) => $v !== '') + ['page' => $n]);
};
?>
<nav class="pagination" aria-label="Pagination">
    <?php if ($page > 1) : ?><a class="btn btn-ghost btn-sm" href="<?= e($link($page - 1)) ?>">&larr; Previous</a><?php endif; ?>
    <span class="muted">Page <?= e($page) ?> of <?= e($pages) ?> &middot; <?= e($total) ?> total</span>
    <?php if ($page < $pages) : ?><a class="btn btn-ghost btn-sm" href="<?= e($link($page + 1)) ?>">Next &rarr;</a><?php endif; ?>
</nav>
