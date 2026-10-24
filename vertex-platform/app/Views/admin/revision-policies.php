<?php /** @var list<array<string,mixed>> $current */ /** @var list<array<string,mixed>> $history */ /** @var bool $canManage */ /** @var list<array<string,mixed>> $projects */
$services = (array) config('workflow.services');
$v = static fn (string $k, string $d = ''): string => old($k, $d);
?>
<div class="page-head"><div><p class="eyebrow">Administration</p><h1>Revision policies</h1></div></div>
<p class="muted">What each agreement says about included revisions. <strong>Nothing is assumed:</strong> until a policy is recorded, no allowance applies and every classification is a reasoned manual decision. A project's own terms win over its service's terms, which win over the default. Use the figures from the approved quotation or contract and cite its reference.</p>
<div class="table-wrap"><table class="table"><thead><tr><th>Applies to</th><th>Included revisions</th><th>Terms</th><th>Source</th><th>Version</th></tr></thead><tbody>
    <?php foreach ($current as $p) : ?><tr><td><?= $p['scope'] === 'default' ? 'Default (all projects)' : ($p['scope'] === 'service' ? 'Service: ' . e($services[$p['service_category']] ?? $p['service_category']) : 'Project: ' . e($p['project_code'] . ' ' . $p['project_title'])) ?></td>
        <td><?= $p['included_revisions'] === null ? '<span class="muted">Not specified</span>' : e($p['included_revisions']) ?></td><td class="prewrap"><?= e($p['terms'] ?? '') ?></td><td><?= e($p['source_reference'] ?? '') ?></td><td>v<?= e($p['version']) ?></td></tr><?php endforeach; ?>
    <?php if ($current === []) : ?><tr><td colspan="5" class="muted">No policy recorded. No allowance is assumed anywhere.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($canManage) : ?>
<div class="panel"><h2>Record or change a policy</h2>
    <form method="post" action="/admin/revision-policies" class="form-grid" novalidate><?= csrf_field() ?>
        <div class="field"><label for="sc">Applies to</label><select id="sc" name="scope"><option value="default"<?= $v('scope') === 'default' ? ' selected' : '' ?>>Default</option><option value="service"<?= $v('scope') === 'service' ? ' selected' : '' ?>>A service</option><option value="project"<?= $v('scope') === 'project' ? ' selected' : '' ?>>One project</option></select></div>
        <div class="field"><label for="sv">Service (if a service)</label><select id="sv" name="service"><option value="">—</option><?php foreach ($services as $c => $l) : ?><option value="<?= e($c) ?>"<?= $v('service') === $c ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="pj">Project (if one project)</label><select id="pj" name="project"><option value="">—</option><?php foreach ($projects as $p) : ?><option value="<?= e($p['public_id']) ?>"<?= $v('project') === $p['public_id'] ? ' selected' : '' ?>><?= e($p['code'] . ' · ' . $p['title']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="inc">Included revisions (leave empty if the agreement gives no number)</label><input id="inc" name="included" inputmode="numeric" maxlength="4" value="<?= e($v('included')) ?>"></div>
        <div class="field"><label for="tm">Written terms</label><textarea id="tm" name="terms" rows="3" maxlength="3000"><?= e($v('terms')) ?></textarea></div>
        <div class="field"><label for="sr">Quotation / contract reference</label><input id="sr" name="source_reference" maxlength="120" value="<?= e($v('source_reference')) ?>"></div>
        <div class="field"><label for="rs">Reason for the change</label><input id="rs" name="reason" required maxlength="255" value="<?= e($v('reason')) ?>"></div>
        <div class="field"><label for="pw">Your password</label><input id="pw" name="confirm_password" type="password" required autocomplete="current-password"></div>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save new version</button></div></form></div>
<?php endif; ?>
<div class="panel"><h2>Version history</h2><div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>Applies to</th><th>Included</th><th>Source</th><th>Version</th><th>By</th><th>Reason</th></tr></thead><tbody>
    <?php foreach ($history as $h) : ?><tr><td><?= e(local_time($h['created_at'])) ?></td><td><?= e($h['scope_key']) ?></td><td><?= $h['included_revisions'] === null ? '—' : e($h['included_revisions']) ?></td><td><?= e($h['source_reference'] ?? '') ?></td>
        <td>v<?= e($h['version']) ?><?= $h['current_flag'] ? ' <span class="badge badge-active">current</span>' : '' ?></td><td><?= e($h['author'] ?? '') ?></td><td><?= e($h['reason'] ?? '') ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
