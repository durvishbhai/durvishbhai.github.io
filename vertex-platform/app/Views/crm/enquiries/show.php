<?php
/** @var array<string,mixed> $e */
/** @var string $level */
/** @var list<array<string,mixed>> $timeline */ /** @var int $timelineTotal */ /** @var int $page */
/** @var list<array<string,mixed>> $followups */ /** @var list<array<string,mixed>> $stages */
/** @var array{strong:list<array<string,mixed>>,weak:list<array<string,mixed>>,hidden:int} $duplicates */
/** @var list<array<string,mixed>> $attachments */ /** @var list<array<string,mixed>> $owners */
/** @var bool $canConvert */ /** @var array{strong:list<array<string,mixed>>,weak:list<array<string,mixed>>} $clientMatches */
/** @var array<string,mixed>|null $convertedProject */
$u = '/crm/enquiries/' . $e['public_id'];
$locked = $e['converted_project_id'] !== null || $e['archived_at'] !== null;
$services = (array) config('workflow.services');
$values = [];
foreach (['contact_name', 'email', 'phone', 'company_name', 'service', 'description', 'budget_range', 'expected_deadline', 'priority'] as $f) {
    $values[$f] = old($f, (string) ($e[$f] ?? ''));
}
?>
<div class="page-head">
    <div><p class="eyebrow">Enquiry <?= e($e['reference']) ?></p><h1><?= e($e['contact_name']) ?><?= $e['company_name'] ? ' <span class="muted">· ' . e($e['company_name']) . '</span>' : '' ?></h1></div>
    <a class="btn btn-ghost" href="/crm/enquiries">&larr; All enquiries</a>
</div>

<?php $extra = \Vertex\Services\EnquiryExtras::details((int) $e['id']); $extraFiles = $extra === null ? [] : \Vertex\Services\EnquiryExtras::files((int) $e['id']); ?>
<?php if ($extra !== null) : ?><div class="panel"><h2>Submitted with the form</h2><dl class="kv"><?php foreach ($extra as $label => $value) : if ($label === 'form') { continue; } ?><dt><?= e((string) $label) ?></dt><dd class="prewrap"><?= e((string) $value) ?></dd><?php endforeach; ?></dl>
    <?php if ($extraFiles !== []) : ?><h3>Attachments</h3><ul class="plain-list"><?php foreach ($extraFiles as $xf) : ?><li><a href="<?= e($u) ?>/files/<?= e($xf['public_id']) ?>"><?= e($xf['original_name']) ?></a> <span class="muted">(<?= e(\Vertex\Services\FileStore::humanSize((int) $xf['size_bytes'])) ?>)</span></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
<?php if ($e['archived_at']) : ?><div class="alert alert-info"><p>This enquiry is archived.</p>
    <?php if (can('enquiries.archive')) : ?><form method="post" action="<?= e($u) ?>/archive" class="inline-form"><?= csrf_field() ?><input type="hidden" name="restore" value="1"><button class="btn btn-ghost btn-sm" type="submit">Restore</button></form><?php endif; ?></div><?php endif; ?>
<?php if ($convertedProject) : ?><div class="alert alert-ok"><p>Converted on <?= e(local_time($e['converted_at'])) ?> to project <a href="/projects/<?= e($convertedProject['public_id']) ?>"><?= e($convertedProject['code']) ?> — <?= e($convertedProject['title']) ?></a>. This enquiry and its history are locked and preserved.</p></div><?php endif; ?>
<?php if ($e['duplicate_status'] === 'suspected' || $duplicates['strong'] || $duplicates['weak'] || $duplicates['hidden']) : ?>
    <div class="panel panel-warn">
        <h2>Possible duplicates</h2>
        <p class="muted">Matched on the same email or phone (strong) or the same company name (weak). Nothing is merged automatically.</p>
        <ul class="plain-list">
            <?php foreach (['strong' => 'same email/phone', 'weak' => 'same company name'] as $k => $why) : foreach ($duplicates[$k] as $d) : ?>
                <li><a href="/crm/enquiries/<?= e($d['public_id']) ?>"><strong><?= e($d['reference']) ?></strong></a> <?= e($d['contact_name']) ?> <span class="badge"><?= e($why) ?></span></li>
            <?php endforeach; endforeach; ?>
        </ul>
        <?php if ($duplicates['hidden'] > 0) : ?><p class="muted"><?= e($duplicates['hidden']) ?> more possible match(es) belong to enquiries you cannot access.</p><?php endif; ?>
        <?php if (!$locked && can('enquiries.move_stage') && $e['stage_kind'] === 'open') : ?>
            <form method="post" action="<?= e($u) ?>/duplicate" class="inline-form" data-confirm="Close this enquiry as a duplicate? It is kept, but moved to Lost.">
                <?= csrf_field() ?><input name="reference" placeholder="Original reference, e.g. ENQ-2026-000001" maxlength="24" required aria-label="Original enquiry reference">
                <button class="btn btn-danger btn-sm" type="submit">Mark as duplicate</button></form>
            <?php if ($e['duplicate_status'] === 'suspected' && can('enquiries.edit')) : ?>
                <form method="post" action="<?= e($u) ?>/duplicate" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="dismiss"><button class="btn btn-ghost btn-sm" type="submit">Not a duplicate</button></form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="grid-2">
    <div class="panel">
        <h2>Summary</h2>
        <dl class="kv">
            <dt>Stage</dt><dd><span class="badge badge-stage-<?= e($e['stage_kind']) ?>"><?= e($e['stage_name']) ?></span></dd>
            <?php if ($e['lost_reason']) : ?><dt>Lost reason</dt><dd><?= e($e['lost_reason']) ?></dd><?php endif; ?>
            <dt>Source</dt><dd><?= e(config("workflow.sources.{$e['source']}", $e['source'])) ?></dd>
            <dt>Service</dt><dd><?= e($services[$e['service']] ?? $e['service']) ?></dd>
            <dt>Priority</dt><dd><span class="badge badge-prio-<?= e($e['priority']) ?>"><?= e(status_label((string) $e['priority'])) ?></span></dd>
            <dt>Owner</dt><dd><?= e($e['owner_name'] ?? 'Unassigned') ?></dd>
            <dt>Email</dt><dd><a href="mailto:<?= e($e['email']) ?>"><?= e($e['email']) ?></a></dd>
            <dt>Phone</dt><dd><?= e($e['phone'] ?? '—') ?></dd>
            <dt>Budget</dt><dd><?= e(config("workflow.budget_ranges.{$e['budget_range']}", '—')) ?></dd>
            <dt>Expected deadline</dt><dd><?= e($e['expected_deadline'] ?? '—') ?></dd>
            <?php if ($e['preferred_slot']) : ?><dt>Preferred slot</dt><dd><?= e($e['preferred_slot']) ?></dd><?php endif; ?>
            <dt>Next follow-up</dt><dd><?= e($e['next_followup_date'] ?? '—') ?></dd>
            <dt>Received</dt><dd><?= e(local_time($e['created_at'])) ?></dd>
            <?php if ($e['consent_at']) : ?><dt>Consent given</dt><dd><?= e(local_time($e['consent_at'])) ?></dd><?php endif; ?>
        </dl>
        <h3>Description</h3>
        <p class="prewrap"><?= e($e['description']) ?></p>
    </div>

    <div>
        <?php if (!$locked && can('enquiries.move_stage')) : ?>
        <div class="panel">
            <h2>Pipeline stage</h2>
            <form method="post" action="<?= e($u) ?>/stage" class="form-grid" data-confirm="Change the stage of this enquiry?">
                <?= csrf_field() ?>
                <div class="field"><label for="stage">Move to</label>
                    <select id="stage" name="stage" required><option value="">Choose a stage…</option>
                        <?php foreach ($stages as $s) : if ((int) $s['id'] !== (int) $e['stage_id']) : ?><option value="<?= e($s['code']) ?>"><?= e($s['name']) ?><?= $s['kind'] === 'won' ? ' (explicit decision)' : '' ?></option><?php endif; endforeach; ?>
                    </select></div>
                <div class="field"><label for="reason">Reason <span class="muted">(required for Lost)</span></label><input id="reason" name="reason" maxlength="255"></div>
                <?php if (can('enquiries.mark_won')) : ?><label class="check"><input type="checkbox" name="confirm" value="1"> I confirm this enquiry is Won (needed only for Won)</label><?php endif; ?>
                <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit">Move</button></div>
            </form>
            <p class="muted">Creating or sending a quotation never changes the stage. Won is always a manual, confirmed decision.</p>
        </div>
        <?php endif; ?>

        <?php if ($owners !== [] && !$locked) : ?>
        <div class="panel"><h2>Owner</h2>
            <form method="post" action="<?= e($u) ?>/assign" class="inline-form"><?= csrf_field() ?>
                <select name="owner" aria-label="Owner"><option value="">Unassigned</option>
                    <?php foreach ($owners as $o) : ?><option value="<?= e($o['id']) ?>"<?= (int) $e['owner_id'] === (int) $o['id'] ? ' selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?></select>
                <button class="btn btn-ghost btn-sm" type="submit">Save owner</button></form></div>
        <?php endif; ?>

        <?php if ($canConvert) : ?>
        <div class="panel panel-ok">
            <h2>Convert to client &amp; project</h2>
            <p class="muted">Creates a client record and a <strong>draft</strong> project. The enquiry and its history stay exactly as they are.</p>
            <form method="post" action="<?= e($u) ?>/convert" class="form-grid" data-confirm="Convert this enquiry? It will be locked afterwards.">
                <?= csrf_field() ?>
                <?php $matches = array_merge($clientMatches['strong'], $clientMatches['weak']); ?>
                <?php if ($matches !== []) : ?>
                    <p><strong>Existing clients that may be the same person or business:</strong></p>
                    <?php foreach ($matches as $m) : ?><label class="check"><input type="radio" name="client" value="<?= e($m['public_id']) ?>"> Link to <?= e($m['contact_name']) ?> <span class="muted">&lt;<?= e($m['email']) ?>&gt; <?= e($m['company_name'] ?? '') ?></span></label><?php endforeach; ?>
                <?php endif; ?>
                <label class="check"><input type="radio" name="client" value="new" checked> Create a new client record</label>
                <?php if ($clientMatches['strong'] !== []) : ?><label class="check"><input type="checkbox" name="confirm_new" value="1"> This is a different client (create anyway)</label><?php endif; ?>
                <div class="field"><label for="project_name">Project name <span class="muted">(optional)</span></label><input id="project_name" name="project_name" maxlength="200"></div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Convert</button></div>
            </form>
        </div>
        <?php endif; ?>

        <div class="panel">
            <h2>Follow-ups</h2>
            <?php if ($followups === []) : ?><p class="muted">No follow-ups scheduled.</p><?php endif; ?>
            <ul class="plain-list">
                <?php foreach ($followups as $f) : $done = $f['completed_at'] !== null; $over = !$done && $f['due_date'] < \Vertex\Services\AppTime::today(); ?>
                    <li class="<?= $done ? 'muted' : '' ?>"><span<?= $over ? ' class="overdue"' : '' ?>><?= e($f['due_date']) ?><?= $over ? ' (overdue)' : '' ?></span> — <?= e($f['note']) ?><?= $done ? ' ✓' : '' ?>
                        <?php if (!$done && can('enquiries.notes')) : ?><form method="post" action="<?= e($u) ?>/followups/<?= e($f['id']) ?>/complete" class="inline-form"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Done</button></form><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (can('enquiries.notes') && !$locked) : ?>
                <form method="post" action="<?= e($u) ?>/followups" class="inline-form"><?= csrf_field() ?>
                    <input type="date" name="due_date" required aria-label="Follow-up date" min="<?= e(\Vertex\Services\AppTime::today()) ?>"><input name="note" placeholder="What to follow up" maxlength="255" required aria-label="Follow-up note">
                    <button class="btn btn-primary btn-sm" type="submit">Schedule</button></form>
                <p class="muted">Shown on the CRM dashboard. No email or message reminders are sent.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid-2">
<div class="panel">
    <h2>Notes &amp; contact history</h2>
    <?php if (can('enquiries.notes')) : ?>
        <form method="post" action="<?= e($u) ?>/notes" class="form-grid"><?= csrf_field() ?>
            <div class="field"><label for="note_body">Internal note</label><textarea id="note_body" name="body" rows="3" maxlength="4000" required></textarea></div>
            <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Add note</button></div></form>
        <form method="post" action="<?= e($u) ?>/contact" class="form-grid"><?= csrf_field() ?>
            <div class="field"><label for="channel">Log a contact</label><select id="channel" name="channel"><?php foreach (config('workflow.contact_channels') as $c => $l) : ?><option value="<?= e($c) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><textarea name="body" rows="3" maxlength="4000" required placeholder="What was discussed" aria-label="What was discussed"></textarea></div>
            <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Log contact</button></div></form>
    <?php endif; ?>
</div>
<div class="panel">
    <h2>Attachments</h2>
    <?php if ($attachments === []) : ?><p class="muted">No files attached.</p><?php endif; ?>
    <ul class="plain-list"><?php foreach ($attachments as $a) : ?>
        <li><a href="/attachments/<?= e($a['public_id']) ?>"><?= e($a['original_name']) ?></a> <span class="muted"><?= e(round($a['size_bytes'] / 1024)) ?> KB · <?= e($a['uploader'] ?? '') ?> · <?= e(local_time($a['created_at'], 'd M Y')) ?></span></li>
    <?php endforeach; ?></ul>
    <?php if (can('enquiries.edit') && !$locked) : ?>
        <form method="post" action="<?= e($u) ?>/attachments" enctype="multipart/form-data" class="inline-form"><?= csrf_field() ?>
            <input type="file" name="file" required aria-label="File"><button class="btn btn-ghost btn-sm" type="submit">Upload</button></form>
        <p class="muted">PDF, PNG, JPG, WebP, TXT, CSV, DOCX, XLSX up to 5 MB.</p>
    <?php endif; ?>
</div>
</div>

<?php if (can('enquiries.edit') && !$locked) : ?>
<div class="panel" id="edit">
    <h2>Edit details</h2>
    <form method="post" action="<?= e($u) ?>" class="form-grid" novalidate><?= csrf_field() ?>
        <?= \Vertex\Core\View::partial('enquiry-fields', ['values' => $values, 'staff' => true]) ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save changes</button></div></form>
</div>
<?php endif; ?>

<div class="panel">
    <h2>Activity history</h2>
    <?= \Vertex\Core\View::partial('timeline', ['items' => $timeline]) ?>
    <?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => 40, 'total' => $timelineTotal, 'filters' => []]) ?>
</div>
