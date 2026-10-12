<?php /** @var string $kind application|referral|commission|payout|payment|partner */ /** @var string $s */
$map = ['application' => 'application_statuses', 'referral' => 'referral_statuses', 'commission' => 'commission_statuses', 'payout' => 'payout_statuses'];
$label = isset($map[$kind]) ? (string) config('referrals.' . $map[$kind] . '.' . $s, $s) : ($kind === 'payment' ? ['pending_verification' => 'Awaiting verification', 'verified' => 'Verified', 'reversed' => 'Reversed', 'rejected' => 'Not confirmed'][$s] ?? $s : ucfirst(str_replace('_', ' ', $s)));
?><span class="badge badge-ps-<?= e($s) ?>"><?= e($label) ?></span>
