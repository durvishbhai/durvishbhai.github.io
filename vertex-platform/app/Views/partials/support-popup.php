<?php /** @var list<array{project:array<string,mixed>,state:array<string,mixed>}> $choices */ ?>
<div class="support-popup" id="support-popup" role="dialog" aria-modal="true" aria-labelledby="support-popup-title" data-support-popup>
    <div class="support-popup-box">
        <h2 id="support-popup-title">Your free support has ended</h2>
        <p>Free support lasts 6 months from final delivery. To keep getting help with bugs, please choose how you want to continue. This message stays until you choose.</p>
        <?php foreach ($choices as $c) : $p = $c['project']; ?>
        <div class="support-popup-project">
            <h3><?= e($p['title']) ?></h3>
            <?php if ($c['state']['awaiting']) : ?><p class="alert alert-ok">Your subscription invoice <?= e((string) $c['state']['awaiting']['invoice_reference']) ?> is waiting for payment. <a class="btn btn-primary btn-sm" href="/my/invoices/<?= e($c['state']['awaiting']['invoice_public_id']) ?>">Pay now</a></p><?php endif; ?>
            <?= \Vertex\Core\View::partial('support-options', ['project' => $p, 'state' => $c['state']]) ?>
        </div>
        <?php endforeach; ?>
        <form method="post" action="/logout" class="support-popup-foot"><?= csrf_field() ?><a href="/my/support">Open the full page</a> <button class="btn btn-ghost btn-sm" type="submit">Sign out</button></form>
    </div>
</div>
