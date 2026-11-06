<?php
/** @var array<string,mixed> $cal */ /** @var string $base */ /** @var string $selected */ /** @var array<string,string> $query */ /** @var bool $admin */
$admin = $admin ?? false;
$query = $query ?? [];
$selected = $selected ?? '';
$href = static fn (array $extra) => $base . '?' . http_build_query($query + $extra);
$labels = ['available' => 'Available', 'full' => 'Fully booked', 'blocked' => 'Unavailable', 'before' => 'Not open yet', 'after' => 'Too far ahead', 'past' => 'Past'];
?>
<div class="cal" role="group" aria-label="Calendar for <?= e($cal['label']) ?>">
    <div class="cal-head">
        <?php if ($cal['prev'] !== null) : ?><a class="btn btn-ghost btn-sm" href="<?= e($href(['month' => $cal['prev']])) ?>" aria-label="Previous month">&larr;</a><?php else : ?><span class="btn btn-ghost btn-sm is-off" aria-hidden="true">&larr;</span><?php endif; ?>
        <strong><?= e($cal['label']) ?></strong>
        <?php if ($cal['next'] !== null) : ?><a class="btn btn-ghost btn-sm" href="<?= e($href(['month' => $cal['next']])) ?>" aria-label="Next month">&rarr;</a><?php else : ?><span class="btn btn-ghost btn-sm is-off" aria-hidden="true">&rarr;</span><?php endif; ?>
    </div>
    <div class="cal-grid cal-dow" aria-hidden="true"><?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dw) : ?><span><?= e($dw) ?></span><?php endforeach; ?></div>
    <?php foreach ($cal['weeks'] as $week) : ?>
    <div class="cal-grid">
        <?php foreach ($week as $d) : ?>
            <?php if ($d === null) : ?><span class="cal-day is-blank" aria-hidden="true"></span><?php continue; endif; ?>
            <?php
            $state = $d['state'];
            $cls = 'cal-day is-' . $state . ($selected === $d['date'] ? ' is-selected' : '');
            $when = local_date($d['date'], 'j F Y');
            if ($admin) {
                $line = ($d['used'] + $d['reserved']) . '/' . $d['cap'];
                $badge = $d['blocked'] ? 'Blocked' : ($d['reserved'] > 0 ? $d['reserved'] . ' reserved' : '');
                ?>
                <a class="<?= e($cls) ?>" href="<?= e('/admin/delivery?' . http_build_query(['month' => $cal['month'], 'date' => $d['date']]) . '#day') ?>"<?= $selected === $d['date'] ? ' aria-current="date"' : '' ?>><span class="cal-n"><?= e($d['day']) ?></span><span class="cal-s"><?= e($line) ?></span><?php if ($badge !== '') : ?><span class="cal-b"><?= e($badge) ?></span><?php endif; ?><span class="sr-only"> <?= e($when) ?></span></a>
            <?php } elseif ($state === 'available') {
                $left = $d['remaining'] . ($d['remaining'] === 1 ? ' slot' : ' slots'); ?>
                <a class="<?= e($cls) ?>" href="<?= e($href(['month' => $cal['month'], 'date' => $d['date']]) . '#review') ?>"<?= $selected === $d['date'] ? ' aria-current="date"' : '' ?>><span class="cal-n"><?= e($d['day']) ?></span><span class="cal-s"><?= e($left) ?></span><span class="sr-only"> <?= e($when) ?>, <?= e($left) ?> available</span></a>
            <?php } else { ?>
                <span class="<?= e($cls) ?>" aria-disabled="true"><span class="cal-n"><?= e($d['day']) ?></span><?php if (in_array($state, ['full', 'blocked'], true)) : ?><span class="cal-s"><?= e($state === 'full' ? 'Fully booked' : 'Unavailable') ?></span><?php endif; ?><span class="sr-only"> <?= e($when) ?>, <?= e($labels[$state]) ?></span></span>
            <?php } ?>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <p class="cal-key muted">
        <?php if ($admin) : ?><span class="key is-available"></span> room left <span class="key is-full"></span> full <span class="key is-blocked"></span> blocked &middot; "2/2" = booked plus reserved out of capacity
        <?php else : ?><span class="key is-available"></span> available <span class="key is-full"></span> fully booked or unavailable <span class="key is-before"></span> not open for this project<?php endif; ?>
    </p>
</div>
