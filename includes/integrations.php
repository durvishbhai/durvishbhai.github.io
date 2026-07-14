<?php
require_once __DIR__ . '/functions.php';
function create_phonepe_order(int $orderId, float $amount): array { return ['provider'=>'phonepe','order_id'=>$orderId,'amount'=>$amount,'status'=>'initialized']; }
function create_demo_payment(int $orderId, float $amount): array { return ['provider'=>'demo','order_id'=>$orderId,'amount'=>$amount,'status'=>'success']; }
function create_shiprocket_order(int $orderId): array {
    if ((int)setting('shiprocket_enabled', 0) !== 1) return ['mode'=>'manual', 'status'=>'disabled'];
    return ['mode'=>'shiprocket', 'status'=>'queued', 'order_id'=>$orderId];
}
