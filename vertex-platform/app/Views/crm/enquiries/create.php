<?php
$values = [];
foreach (['contact_name', 'email', 'phone', 'company_name', 'service', 'description', 'budget_range', 'expected_deadline', 'priority'] as $f) {
    $values[$f] = old($f);
}
?>
<p class="eyebrow">CRM</p>
<h1>New enquiry</h1>
<p class="muted">For enquiries received by phone, email, message or in person. It starts in the first pipeline stage and a reference number is generated.</p>
<div class="panel">
    <form method="post" action="/crm/enquiries" class="form-grid" novalidate>
        <?= csrf_field() ?>
        <?= \Vertex\Core\View::partial('enquiry-fields', ['values' => $values, 'staff' => true]) ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Create enquiry</button> <a class="btn btn-ghost" href="/crm/enquiries">Cancel</a></div>
    </form>
</div>
