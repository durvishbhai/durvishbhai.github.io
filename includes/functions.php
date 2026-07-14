<?php
require_once __DIR__ . '/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): void { header('Location: ' . $url); exit; }
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $st = db()->prepare('SELECT id,name,email,phone,is_admin,status FROM users WHERE id=? LIMIT 1');
    $st->execute([$_SESSION['user_id']]);
    return $st->fetch() ?: null;
}
function is_admin(): bool { $u = current_user(); return $u && (int)$u['is_admin'] === 1; }
function require_login(): void { if (!current_user()) redirect('/login.php'); }
function require_admin(): void { if (!is_admin()) redirect('/login.php'); }
function setting(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $rows = db()->query('SELECT `key`,`value` FROM settings')->fetchAll();
        foreach ($rows as $r) $cache[$r['key']] = $r['value'];
    }
    return $cache[$key] ?? $default;
}
function refresh_settings_cache(): void { $GLOBALS['__dummy']=null; }
function money(float $amount): string { return '₹' . number_format($amount, 2); }
function cart_items(): array {
    $items = $_SESSION['cart'] ?? [];
    if (!$items) return [];
    $ids = array_keys($items);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT * FROM products WHERE id IN ($in) AND status=1");
    $st->execute($ids);
    $prods = $st->fetchAll();
    $out = [];
    foreach ($prods as $p) {
        $qty = max(1, (int)$items[$p['id']]);
        $line = $qty * (float)$p['sale_price'];
        $out[] = ['product' => $p, 'qty' => $qty, 'line_total' => $line];
    }
    return $out;
}
function cart_totals(): array {
    $items = cart_items();
    $subtotal = array_reduce($items, fn($c,$i)=>$c+$i['line_total'], 0.0);
    $deliveryEnabled = (int)setting('delivery_enable',1)===1;
    $deliveryCharge = (float)setting('delivery_charge',0);
    $freeMin = (float)setting('free_delivery_min',999999);
    $delivery = ($deliveryEnabled && $subtotal < $freeMin) ? $deliveryCharge : 0;
    return ['items'=>$items,'subtotal'=>$subtotal,'delivery'=>$delivery,'grand_total'=>$subtotal+$delivery];
}
