<?php /** @var list<array{project:array<string,mixed>,deliverables:list<array<string,mixed>>,open_revisions:int}> $groups */ use Vertex\Core\View; ?>
<div class="page-head"><div><p class="eyebrow">Your projects</p><h1>My deliverables</h1></div><a class="btn btn-ghost" href="/revisions">My revision requests</a></div>
<p class="muted">Work appears here once our team has checked it and published it to you. Opening or downloading a file never counts as approval: you decide below.</p>
<?php if ($groups === []) : ?><div class="panel"><p class="muted">You have no projects yet.</p></div><?php endif; ?>
<?php foreach ($groups as $g) : $p = $g['project']; ?>
    <div class="panel"><h2><a href="/projects/<?= e($p['public_id']) ?>"><?= e($p['title']) ?></a> <span class="muted"><?= e($p['code']) ?></span></h2>
        <?php if ((int) $g['open_revisions'] > 0) : ?><p><a href="/revisions?open=1"><?= e($g['open_revisions']) ?> revision request<?= (int) $g['open_revisions'] === 1 ? '' : 's' ?> in progress</a></p><?php endif; ?>
        <?php if ($g['deliverables'] === []) : ?><p class="muted">No deliverables published yet.</p><?php else : ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Deliverable</th><th>Category</th><th>Current version</th><th>Published</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($g['deliverables'] as $row) : $d = $row['d']; $s = $row['state']; ?>
                <tr><td><a href="/deliverables/<?= e($d['public_id']) ?>"><strong><?= e($d['title']) ?></strong></a><br><span class="muted"><?= e($d['code']) ?></span></td>
                    <td><?= e(config("workflow.deliverable_categories.{$d['category']}", $d['category'])) ?></td>
                    <td><?= $s['version'] ? 'V' . e($s['version']['version_no']) : '—' ?></td>
                    <td><?= $s['version'] && $s['version']['published_at'] ? e(local_time($s['version']['published_at'], 'd M Y')) : '—' ?></td>
                    <td><?= View::partial('client-state', ['key' => $s['key'], 'label' => $s['label']]) ?></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
<?php endforeach; ?>
