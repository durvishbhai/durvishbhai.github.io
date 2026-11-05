<?php /** @var list<array<string,mixed>> $rows */ /** @var string $filter */
$tabs = ['all' => 'All', 'free' => 'In free support', 'choice' => 'Must choose', 'subscription' => 'Subscribed', 'per_bug' => 'Pay per bug', 'none' => 'No free period'];
?>
<div class="page-head"><div><p class="eyebrow">Support</p><h1>Bug-support plans</h1></div></div>
<p class="muted">Finished projects and where their support stands. Free support is 6 months from final delivery. After that the client must choose a subscription or pay per bug (they see a message in the portal until they do).</p>
<nav class="tabs" aria-label="Filter"><?php foreach ($tabs as $k => $l) : ?><a href="/support-plans?show=<?= e($k) ?>"<?= $filter === $k ? ' class="is-active" aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?></nav>
<?php if ($rows === []) : ?><div class="panel"><p>Nothing in this list.</p></div><?php else : ?>
<div class="table-wrap"><table class="table"><thead><tr><th scope="col">Project</th><th scope="col">Client</th><th scope="col">Free support ends</th><th scope="col">Position</th></tr></thead><tbody>
<?php foreach ($rows as $r) : $p = $r['project']; $s = $r['state']; ?>
    <tr><td><a href="/projects/<?= e($p['public_id']) ?>/warranty"><strong><?= e($p['title']) ?></strong></a></td><td><?= e((string) ($p['company_name'] ?: $p['client_name'])) ?></td><td><?= $s['free_end'] ? e(local_date($s['free_end'])) : '—' ?></td><td><?= e($s['label']) ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
