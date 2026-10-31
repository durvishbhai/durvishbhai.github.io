<?php /** @var list<array<string,mixed>> $files */ /** @var string $base */ /** @var bool $canDownload */
use Vertex\Services\FileStore; ?>
<?php foreach ($files as $f) : ?>
    <p class="file-line"><?php if ($canDownload) : ?><a href="<?= e($base) ?>/files/<?= e($f['public_id']) ?>"><?= e($f['original_name']) ?></a><?php else : ?><?= e($f['original_name']) ?><?php endif; ?>
        <span class="muted"><?= e(FileStore::humanSize((int) $f['size_bytes'])) ?><?= isset($f['visibility']) && $f['visibility'] === 'internal' ? ' · staff only' : '' ?></span></p>
<?php endforeach; ?>
