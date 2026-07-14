<?php require_once __DIR__ . '/functions.php'; $user=current_user(); ?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Adhishakti Enterprises</title><link rel="stylesheet" href="/assets/css/style.css"></head><body>
<header class="topbar"><a href="/" class="brand"><img src="/assets/img/logo.jpg" alt="logo"><span>Adhishakti Enterprises</span></a>
<nav><a href="/products.php">Products</a><a href="/cart.php">Cart</a><?php if($user): ?><a href="/account.php">Account</a><?php if((int)$user['is_admin']===1): ?><a href="/admin/">Admin</a><?php endif; ?><a href="/logout.php">Logout</a><?php else: ?><a href="/login.php">Login</a><?php endif; ?></nav></header><main class="container">
