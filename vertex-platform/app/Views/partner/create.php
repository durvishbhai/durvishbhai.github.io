<?php $values = []; foreach (['contact_name', 'email', 'phone', 'company_name', 'service', 'description', 'budget_range', 'expected_deadline'] as $f) { $values[$f] = old($f); } ?>
<p class="eyebrow">Referral partner</p><h1>Submit a referral</h1>
<p class="muted">Only refer people who have agreed to be contacted. Commission rules are applied later by the business; submitting does not promise any payment.</p>
<div class="panel"><form method="post" action="/partner/referrals" class="form-grid" novalidate><?= csrf_field() ?>
    <?= \Vertex\Core\View::partial('enquiry-fields', ['values' => $values, 'staff' => false]) ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Submit referral</button> <a class="btn btn-ghost" href="/partner/referrals">Cancel</a></div></form></div>
