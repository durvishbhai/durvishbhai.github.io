<?php /** @var list<array<string,mixed>> $rows */ /** @var int $total */ /** @var int $page */ /** @var int $perPage */ ?>
<div class="page-head"><div><p class="eyebrow"><a href="/account">My account</a></p><h1>My account activity</h1></div></div>
<p class="muted">Your recent sign-ins and the actions on your account. If you see a sign-in you do not recognise, <a href="/account#change-password">change your password</a> straight away. Times are in your time zone.</p>
<?= \Vertex\Core\View::partial('activity-rows', ['rows' => $rows, 'showActor' => false]) ?>
<?= \Vertex\Core\View::partial('pagination', ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'filters' => []]) ?>
