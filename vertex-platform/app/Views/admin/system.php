<?php /** @var list<array{group:string,label:string,state:string,detail:string}> $rows */ $group = null; ?>
<div class="page-head"><div><p class="eyebrow">Administration</p><h1>System status</h1></div></div>
<p class="muted">What this system can verify about itself. A warning is something that is not set up or not running: nothing here is assumed to work. No secrets are shown.</p>
<div class="table-wrap"><table class="table"><thead><tr><th>Check</th><th>State</th><th>Detail</th></tr></thead><tbody>
    <?php foreach ($rows as $r) : if ($r['group'] !== $group) : $group = $r['group']; ?><tr><td colspan="3"><strong><?= e($group) ?></strong></td></tr><?php endif; ?>
    <tr><td><?= e($r['label']) ?></td><td><span class="badge badge-sys-<?= e($r['state']) ?>"><?= e(['ok' => 'OK', 'warn' => 'Attention', 'info' => 'Info'][$r['state']]) ?></span></td><td><?= e($r['detail']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<p class="muted">Audit-log integrity is checked from the server console: <code>php bin/console.php audit:verify</code>.</p>
