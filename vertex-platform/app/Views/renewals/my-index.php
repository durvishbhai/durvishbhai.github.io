<?php /** @var list<array<string,mixed>> $rows */ use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">My account</p><h1>Domains &amp; hosting</h1></div></div>
<?php if ($rows === []) : ?><div class="panel"><p>You have no domains or hosting services recorded with us yet.</p></div><?php else : ?>
<div class="table-wrap"><table class="table"><thead><tr><th>Service</th><th>Type</th><th>Expires</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r) : ?><tr><td><a href="/my/services/<?= e($r['public_id']) ?>"><strong><?= e($r['identifier']) ?></strong></a></td><td><?= e(config("renewals.types.{$r['type']}", $r['type'])) ?></td><td><?= e(local_date($r['expires_on'])) ?></td><td><?= View::partial('rbadge', ['s' => $r['status']]) ?><br><span class="muted"><?= e($r['stage']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
