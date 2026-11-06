<?php /** @var array<string,mixed> $project */ /** @var array<string,mixed> $state */
use Vertex\Services\SupportPlans;
$m = SupportPlans::priceMinor('monthly'); $y = SupportPlans::priceMinor('yearly'); $save = SupportPlans::yearlySavingMinor();
$rupees = static fn (int $minor): string => '₹' . number_format($minor / 100);
$id = 'sp-' . substr((string) $project['public_id'], 0, 8);
?>
<div class="support-options">
    <section class="support-option" aria-labelledby="<?= e($id) ?>-h1">
        <h3 id="<?= e($id) ?>-h1">Option 1: Bug-support subscription</h3>
        <p>We fix <strong>every bug</strong> on this project, <strong>major or minor</strong>, for as long as your subscription is active.</p>
        <form method="post" action="/my/support/<?= e($project['public_id']) ?>/subscribe" class="form-grid" novalidate><?= csrf_field() ?>
            <fieldset class="field"><legend class="sr-only">Plan</legend>
                <label class="check"><input type="radio" name="plan" value="monthly" checked> <span><strong><?= e($rupees($m)) ?> per month</strong></span></label>
                <label class="check"><input type="radio" name="plan" value="yearly"> <span><strong><?= e($rupees($y)) ?> per year</strong> <?= $save > 0 ? '<span class="badge badge-d-confirmed">You save ' . e($rupees($save)) . '</span>' : '' ?></span></label>
            </fieldset>
            <div class="field"><label for="<?= e($id) ?>-c">Coupon code (if you have one)</label><input id="<?= e($id) ?>-c" name="coupon_code" maxlength="30" autocomplete="off" value="<?= e(old('coupon_code')) ?>"></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Subscribe and pay</button></div>
            <p class="muted small">You pay in this portal. The subscription starts as soon as the payment is confirmed. Renew any time; nothing is charged automatically.</p>
        </form>
    </section>
    <section class="support-option" aria-labelledby="<?= e($id) ?>-h2">
        <h3 id="<?= e($id) ?>-h2">Option 2: Pay for each bug separately</h3>
        <p>When something breaks, we look at it and tell you <strong>what it actually costs to fix</strong>. You pay only that amount, and only after you have approved it.</p>
        <?php if (($state['mode'] ?? null) === 'per_bug') : ?><p class="badge badge-d-confirmed">This is your current choice</p><?php else : ?>
        <form method="post" action="/my/support/<?= e($project['public_id']) ?>/per-bug"><?= csrf_field() ?><button class="btn btn-ghost" type="submit">Pay per bug</button></form>
        <?php endif; ?>
    </section>
</div>
