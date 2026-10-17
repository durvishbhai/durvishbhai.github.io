<?php

declare(strict_types=1);

// Child process of PayoutServiceTest::testConcurrentRequests*: waits for a shared start time, then requests a payout. Prints one JSON line.
// Usage: php payout_race.php <userId> <rupees> <startMicrotime>
require dirname(__DIR__) . '/bootstrap.php';

[, $user, $amount, $start] = $argv;
while (microtime(true) < (float) $start) {
    usleep(200);
}
try {
    $r = Vertex\Services\PayoutService::request((int) $user, ['amount' => $amount, 'notes' => '', 'token' => bin2hex(random_bytes(16))]);
    echo json_encode(['ok' => true, 'ref' => $r['reference']]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()]), "\n";
}
