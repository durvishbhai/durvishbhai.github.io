<?php /** @var array<string,mixed> $r */ /** @var list<array<string,mixed>> $points */ /** @var bool $staff */ /** @var bool $canAdd */ use Vertex\Services\FeedbackPointService;
$u = '/revisions/' . $r['public_id'] . '/feedback'; ?>
<div class="panel"><h2>Feedback points</h2>
    <?php if ($points === []) : ?><p class="muted">No feedback points yet.<?= $canAdd ? ' Add one for each thing you want changed: for a video give the time it happens, for a design leave the time empty.' : '' ?></p><?php endif; ?>
    <ol class="feedback-list"><?php foreach ($points as $p) : ?><li class="<?= $p['resolved_at'] ? 'is-done' : '' ?>"><?= $p['at_seconds'] !== null ? '<strong class="timecode">' . e(FeedbackPointService::format((int) $p['at_seconds'])) . '</strong> ' : '' ?><?= e($p['note']) ?><?= $p['resolved_at'] ? ' <span class="badge">done</span>' : '' ?>
        <?php if ($staff) : ?><form method="post" action="<?= e($u) ?>/<?= (int) $p['id'] ?>" class="inline-form"><?= csrf_field() ?><input type="hidden" name="done" value="<?= $p['resolved_at'] ? '0' : '1' ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= $p['resolved_at'] ? 'Reopen' : 'Mark done' ?></button></form><?php endif; ?></li><?php endforeach; ?></ol>
    <?php if ($canAdd) : ?><form method="post" action="<?= e($u) ?>" class="form-grid"><?= csrf_field() ?>
        <div class="field"><label for="fa">Time <span class="muted">(optional, e.g. 1:15)</span></label><input id="fa" name="at" maxlength="10" inputmode="numeric" placeholder="1:15"></div>
        <div class="field"><label for="fn">What should change?</label><input id="fn" name="note" required maxlength="1000"></div>
        <div class="form-actions"><button class="btn btn-ghost btn-sm" type="submit">Add feedback point</button></div></form><?php endif; ?></div>
