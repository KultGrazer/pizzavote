<?php
// ============================================================
//  PizzaVote – Frontend
//  Datei: index.php
// ============================================================
require_once __DIR__ . '/config.php';

function getClientIP(): string {
    foreach (['HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
    }
    return '0.0.0.0';
}

$db = getDB();
$ip = getClientIP();

// ── User per IP suchen ───────────────────────────────────────
$user = $db->prepare('SELECT * FROM users WHERE ip = ?');
$user->execute([$ip]);
$user = $user->fetch();

// Neuer Name abgeschickt
if (!$user && isset($_POST['name']) && trim($_POST['name']) !== '') {
    $name = mb_substr(trim($_POST['name']), 0, 80);
    $db->prepare('INSERT INTO users (name, ip) VALUES (?, ?)')->execute([$name, $ip]);
    $id   = $db->lastInsertId();
    $user = ['id' => $id, 'name' => $name, 'ip' => $ip];
}

// ── Aktive Bestellung ────────────────────────────────────────
$order = $db->query(
    "SELECT * FROM orders WHERE status = 'active' ORDER BY created_at DESC LIMIT 1"
)->fetch();

// Abgelaufene Deadline schließen
if ($order && $order['deadline'] && strtotime($order['deadline']) < time()) {
    $db->prepare("UPDATE orders SET status='closed', closed_at=datetime('now','localtime') WHERE id=?")
       ->execute([$order['id']]);
    $order['status'] = 'closed';
}

// ── AJAX: Bestellung speichern ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'save_order' && $user && $order && $order['status'] === 'active') {
        $productId = (int)$_POST['product_id'];
        $comment   = mb_substr(trim($_POST['comment'] ?? ''), 0, 300);
        $prod = $db->prepare('SELECT id FROM products WHERE id = ? AND active = 1');
        $prod->execute([$productId]);
        if (!$prod->fetch()) { echo json_encode(['ok'=>false,'error'=>t('front.error.invalid_product')]); exit; }
        // SQLite UPSERT
        $db->prepare('
            INSERT INTO order_items (order_id, user_id, product_id, comment)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(order_id, user_id)
            DO UPDATE SET product_id = excluded.product_id,
                          comment    = excluded.comment,
                          updated_at = datetime(\'now\',\'localtime\')
        ')->execute([$order['id'], $user['id'], $productId, $comment]);
        echo json_encode(['ok' => true]);
        exit;
    }
    // Namen ändern
    if ($_POST['action'] === 'rename' && $user) {
        $newName = mb_substr(trim($_POST['name'] ?? ''), 0, 80);
        if ($newName === '') { echo json_encode(['ok'=>false,'error'=>t('front.error.empty_name')]); exit; }
        $db->prepare("UPDATE users SET name=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$newName, $user['id']]);
        echo json_encode(['ok'=>true,'name'=>$newName]);
        exit;
    }
    echo json_encode(['ok'=>false,'error'=>t('front.error.invalid_request')]);
    exit;
}

// ── Vorherige Auswahl ────────────────────────────────────────
$prevItem = null;
if ($user && $order) {
    $s = $db->prepare('SELECT product_id, comment FROM order_items WHERE order_id=? AND user_id=?');
    $s->execute([$order['id'], $user['id']]);
    $prevItem = $s->fetch();
}

// ── Produkte laden ───────────────────────────────────────────
$products = [];
if ($order && $order['status'] === 'active') {
    $products = $db->query('SELECT * FROM products WHERE active=1 ORDER BY sort_order, id')->fetchAll();
}

// ── Timer ────────────────────────────────────────────────────
$secondsLeft  = ($order && $order['deadline']) ? max(0, strtotime($order['deadline']) - time()) : null;
$totalSeconds = ($order && $order['deadline'] && $order['created_at'])
    ? max(1, strtotime($order['deadline']) - strtotime($order['created_at']))
    : null;

// ── Letzte Bestellung (ausgegraut, max. 24h) ─────────────────
$lastOrder = $lastOrderItems = $lastPrevItem = $lastProducts = null;
if (!$order || $order['status'] !== 'active') {
    $lo = $db->query(
        "SELECT * FROM orders WHERE status='closed'
         ORDER BY closed_at DESC, created_at DESC LIMIT 1"
    )->fetch();
    if ($lo && $lo['closed_at'] && strtotime($lo['closed_at']) > time() - 86400) {
        $lastOrder = $lo;
        $li = $db->prepare('
            SELECT oi.*, oi.user_id,
                   u.name AS user_name,
                   p.id AS product_id, p.name AS product_name,
                   p.image_url, p.price, p.description
            FROM order_items oi
            JOIN users    u ON u.id = oi.user_id
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
            ORDER BY p.sort_order, p.id
        ');
        $li->execute([$lastOrder['id']]);
        $lastOrderItems = $li->fetchAll();
        if ($user) {
            foreach ($lastOrderItems as $row) {
                if ((int)$row['user_id'] === (int)$user['id']) { $lastPrevItem = $row; break; }
            }
        }
        $seenIds = [];
        $lastProducts = [];
        foreach ($lastOrderItems as $row) {
            if (!in_array($row['product_id'], $seenIds)) {
                $seenIds[] = $row['product_id'];
                $lastProducts[] = $row;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= APP_LANG ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(t('app.name')) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🍕</text></svg>">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700;900&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:      #0e0e0f;
    --surface: #171718;
    --surface2:#1f1f21;
    --border:  #2a2a2d;
    --accent:  #e8651a;
    --accent2: #f0a500;
    --text:    #f0ede8;
    --muted:   #7a7875;
    --success: #4caf7d;
    --r:       16px;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: var(--bg); color: var(--text);
    font-family: 'DM Sans', sans-serif; min-height: 100vh; overflow-x: hidden;
  }
  body::before {
    content: ''; position: fixed; top: -20vh; left: 50%; transform: translateX(-50%);
    width: 80vw; height: 60vh;
    background: radial-gradient(ellipse, rgba(232,101,26,.07) 0%, transparent 70%);
    pointer-events: none; z-index: 0;
  }

  /* ── NAME SCREEN ── */
  #nameScreen {
    position: fixed; inset: 0; display: flex;
    align-items: center; justify-content: center;
    background: var(--bg); z-index: 100;
  }
  .name-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 24px; padding: 52px 48px;
    width: min(480px, 90vw); text-align: center;
    box-shadow: 0 40px 80px rgba(0,0,0,.6);
    animation: slideUp .5s ease;
  }
  .pizza-spin { font-size: 52px; margin-bottom: 20px; display: block; animation: spin 20s linear infinite; }
  @keyframes spin { to { transform: rotate(360deg); } }
  .name-card h1 {
    font-family: 'Playfair Display', serif; font-size: 2rem; font-weight: 900; margin-bottom: 8px;
    background: linear-gradient(135deg, var(--text), var(--accent2));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  }
  .name-card p { color: var(--muted); font-size: .95rem; margin-bottom: 32px; }
  .field { margin-bottom: 16px; }
  .field input {
    width: 100%; background: var(--surface2); border: 1.5px solid var(--border);
    border-radius: 12px; color: var(--text); font-family: 'DM Sans', sans-serif;
    font-size: 1rem; padding: 15px 20px; outline: none;
    transition: border-color .2s, box-shadow .2s;
  }
  .field input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(232,101,26,.15); }
  .field input::placeholder { color: var(--muted); }
  .btn-primary {
    width: 100%; background: var(--accent); border: none; border-radius: 12px;
    color: #fff; cursor: pointer; font-family: 'DM Sans', sans-serif;
    font-size: 1rem; font-weight: 500; padding: 16px; margin-top: 4px;
    transition: background .2s, transform .1s, box-shadow .2s;
    box-shadow: 0 4px 20px rgba(232,101,26,.3);
  }
  .btn-primary:hover { background: #f07328; transform: translateY(-1px); }
  .btn-primary:active { transform: translateY(0); }

  /* ── APP ── */
  #app { position: relative; z-index: 1; }

  .page-top { padding: 36px 32px 0; max-width: 900px; margin: 0 auto; }
  .page-brand {
    display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px;
  }
  .brand-title {
    font-family: 'Playfair Display', serif; font-size: 1.6rem; font-weight: 900;
    display: flex; align-items: center; gap: 10px;
  }
  .user-chip {
    display: flex; align-items: center; gap: 10px;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 50px; padding: 8px 16px; font-size: .9rem;
    cursor: pointer; transition: border-color .2s, background .2s;
    user-select: none;
  }
  .user-chip:hover { border-color: rgba(232,101,26,.5); background: #1f1f21; }
  .user-chip .edit-hint { font-size: .75rem; color: var(--muted); margin-left: 2px; }
  .avatar {
    width: 28px; height: 28px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 50%; display: flex; align-items: center;
    justify-content: center; font-size: .75rem; font-weight: 700;
  }

  /* Timer */
  .timer-block {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r); padding: 14px 22px;
    display: flex; align-items: center; gap: 14px; margin-bottom: 32px;
  }
  .timer-dot {
    width: 9px; height: 9px; background: var(--success);
    border-radius: 50%; flex-shrink: 0; animation: pulse 2s ease infinite;
  }
  @keyframes pulse { 50% { opacity: .4; transform: scale(.8); } }
  .timer-label { font-size: .85rem; color: var(--muted); white-space: nowrap; }
  .progress-wrap { flex: 1; height: 4px; background: var(--border); border-radius: 2px; overflow: hidden; }
  .progress-fill {
    height: 100%; width: 100%;
    background: linear-gradient(90deg, var(--accent), var(--accent2));
    border-radius: 2px; transition: width 1s linear;
  }
  .timer-count {
    font-family: 'Playfair Display', serif; font-size: 1.05rem;
    font-weight: 700; color: var(--accent2); letter-spacing: 2px; white-space: nowrap;
  }

  /* Main */
  .main { max-width: 900px; margin: 0 auto; padding: 0 32px 60px; }
  .section-title { font-family: 'Playfair Display', serif; font-size: 1.7rem; font-weight: 700; margin-bottom: 6px; }
  .section-sub { color: var(--muted); font-size: .95rem; margin-bottom: 28px; }

  /* Product grid */
  .product-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 18px; margin-bottom: 32px;
  }
  .product-card {
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--r); overflow: hidden; cursor: pointer; position: relative;
    transition: transform .2s, border-color .2s, box-shadow .2s;
    animation: fadeIn .4s ease both;
  }
  <?php foreach (range(1,10) as $i): ?>
  .product-card:nth-child(<?= $i ?>) { animation-delay: <?= $i * 0.06 ?>s; }
  <?php endforeach; ?>
  .product-card:hover { transform: translateY(-4px); border-color: rgba(232,101,26,.4); box-shadow: 0 12px 40px rgba(0,0,0,.4); }
  .product-card.selected { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(232,101,26,.2), 0 12px 40px rgba(0,0,0,.4); }
  .check-badge {
    position: absolute; top: 12px; right: 12px;
    width: 30px; height: 30px; background: var(--accent); border-radius: 50%;
    display: none; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 700; z-index: 2;
  }
  .product-card.selected .check-badge { display: flex; }
  .product-img { height: 160px; overflow: hidden; background: var(--surface2); display: flex; align-items: center; justify-content: center; }
  .product-img img { width: 100%; height: 100%; object-fit: cover; transition: transform .4s ease; }
  .product-card:hover .product-img img { transform: scale(1.06); }
  .product-img-fallback { font-size: 3.5rem; }
  .product-info { padding: 16px 18px; }
  .product-name { font-family: 'Playfair Display', serif; font-size: 1.05rem; font-weight: 700; margin-bottom: 4px; }
  .product-desc { font-size: .82rem; color: var(--muted); line-height: 1.5; margin-bottom: 10px; }
  .product-price { font-size: .95rem; font-weight: 500; color: var(--accent2); }

  /* Comment */
  .comment-block {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r); padding: 22px; margin-bottom: 24px;
    animation: fadeIn .5s .35s both;
  }
  .comment-block label { display: block; font-size: .88rem; color: var(--muted); font-weight: 500; margin-bottom: 10px; }
  .comment-block textarea {
    width: 100%; background: var(--surface2); border: 1.5px solid var(--border);
    border-radius: 10px; color: var(--text); font-family: 'DM Sans', sans-serif;
    font-size: .95rem; padding: 12px 16px; resize: none; height: 76px;
    outline: none; transition: border-color .2s;
  }
  .comment-block textarea:focus { border-color: var(--accent); }
  .comment-block textarea::placeholder { color: var(--muted); }

  /* Submit */
  .btn-submit {
    width: 100%; background: linear-gradient(135deg, var(--accent), #c8511a);
    border: none; border-radius: 14px; color: #fff; cursor: pointer;
    font-family: 'DM Sans', sans-serif; font-size: 1.05rem; font-weight: 500; padding: 18px;
    box-shadow: 0 6px 24px rgba(232,101,26,.35);
    display: flex; align-items: center; justify-content: center; gap: 10px;
    transition: opacity .2s, transform .15s, box-shadow .2s;
    animation: fadeIn .5s .4s both;
  }
  .btn-submit:not(:disabled):hover { transform: translateY(-2px); box-shadow: 0 10px 32px rgba(232,101,26,.45); }
  .btn-submit:disabled { opacity: .35; cursor: not-allowed; }

  /* Success */
  .success-banner {
    display: none; background: rgba(76,175,125,.08);
    border: 1px solid rgba(76,175,125,.25); border-radius: 14px;
    padding: 22px; text-align: center; margin-bottom: 28px;
  }
  .success-banner.show { display: block; animation: fadeIn .4s ease; }
  .success-banner .s-icon { font-size: 2.2rem; margin-bottom: 8px; }
  .success-banner h3 { font-family: 'Playfair Display', serif; font-size: 1.25rem; color: var(--success); margin-bottom: 4px; }
  .success-banner p { color: var(--muted); font-size: .88rem; }

  /* No order */
  .no-order { text-align: center; padding: 80px 20px; animation: fadeIn .5s ease; }
  .no-order .no-icon { font-size: 4rem; margin-bottom: 20px; opacity: .3; }
  .no-order h2 { font-family: 'Playfair Display', serif; font-size: 1.6rem; color: var(--muted); margin-bottom: 10px; }
  .no-order p { color: var(--muted); font-size: .9rem; }

  /* Rename modal */
  .rename-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.7);
    display: flex; align-items: center; justify-content: center;
    z-index: 200; opacity: 0; pointer-events: none; transition: opacity .2s;
  }
  .rename-modal-overlay.open { opacity: 1; pointer-events: all; }
  .rename-modal {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 20px; padding: 32px; width: min(400px, 90vw);
    transform: translateY(16px); transition: transform .2s;
    box-shadow: 0 40px 80px rgba(0,0,0,.6);
  }
  .rename-modal-overlay.open .rename-modal { transform: translateY(0); }
  .rename-modal h3 { font-family: 'Playfair Display', serif; font-size: 1.2rem; font-weight: 700; margin-bottom: 20px; }
  .rename-modal input {
    width: 100%; background: var(--surface2); border: 1.5px solid var(--border);
    border-radius: 12px; color: var(--text); font-family: 'DM Sans', sans-serif;
    font-size: 1rem; padding: 13px 18px; outline: none; margin-bottom: 14px;
    transition: border-color .2s;
  }
  .rename-modal input:focus { border-color: var(--accent); }
  .rename-info {
    display: flex; align-items: flex-start; gap: 8px;
    background: rgba(232,101,26,.08); border: 1px solid rgba(232,101,26,.2);
    border-radius: 10px; padding: 10px 14px; margin-bottom: 16px;
    font-size: .82rem; color: var(--muted); line-height: 1.4;
  }
  .rename-modal-footer { display: flex; gap: 10px; justify-content: flex-end; }
  .btn-modal-cancel { background: var(--surface2); border: 1px solid var(--border); border-radius: 10px; color: var(--text); cursor: pointer; font-family: 'DM Sans', sans-serif; font-size: .9rem; padding: 10px 18px; }
  .btn-modal-save { background: var(--accent); border: none; border-radius: 10px; color: #fff; cursor: pointer; font-family: 'DM Sans', sans-serif; font-size: .9rem; font-weight: 500; padding: 10px 18px; box-shadow: 0 4px 16px rgba(232,101,26,.3); }
  .btn-modal-save:hover { background: #f07328; }

  /* Frozen / last order */
  .no-order-hint {
    display: flex; align-items: center; gap: 14px;
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--r); padding: 14px 20px; margin-bottom: 28px;
    font-size: .9rem;
  }
  .no-order-hint-icon { font-size: 1.4rem; opacity: .5; }
  .no-order-hint strong { display: block; margin-bottom: 2px; }
  .no-order-hint span { color: var(--muted); font-size: .82rem; }

  .product-grid-frozen { pointer-events: none; }
  .product-card.frozen { opacity: .45; filter: grayscale(30%); cursor: default; }
  .product-card.frozen-mine { opacity: .7 !important; filter: none !important; border-color: rgba(232,101,26,.3); }
  .product-card.frozen:hover { transform: none; box-shadow: none; border-color: var(--border); }
  .product-card.frozen-mine:hover { transform: none; }
  .frozen-count {
    position: absolute; top: 10px; left: 12px;
    background: rgba(0,0,0,.55); border-radius: 20px;
    padding: 2px 9px; font-size: .78rem; font-weight: 600;
    color: var(--text); z-index: 2;
  }

  @keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
  @keyframes slideUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }

  @media (max-width: 600px) {
    .page-top, .main { padding-left: 18px; padding-right: 18px; }
    .product-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
    .name-card { padding: 36px 24px; }
  }
</style>
</head>
<body>

<?php if (!$user): ?>
<!-- ── NAME SCREEN ── -->
<div id="nameScreen">
  <div class="name-card">
    <span class="pizza-spin">🍕</span>
    <h1><?= htmlspecialchars(t('front.welcome.title')) ?></h1>
    <p><?= htmlspecialchars(t('front.welcome.subtitle')) ?></p>
    <form method="post">
      <div class="field">
        <input type="text" name="name" placeholder="<?= htmlspecialchars(t('common.name_placeholder')) ?>"
               maxlength="80" autocomplete="off" autofocus required />
      </div>
      <button type="submit" class="btn-primary"><?= htmlspecialchars(t('front.welcome.continue')) ?></button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── APP ── -->
<div id="app">

  <div class="page-top">
    <div class="page-brand">
      <div class="brand-title">🍕 <?= htmlspecialchars(t('app.name')) ?></div>
      <div class="user-chip" onclick="openRename()" title="<?= htmlspecialchars(t('front.rename.tooltip')) ?>">
        <div class="avatar" id="userAvatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($user['name'],0,1))) ?></div>
        <span id="userName"><?= htmlspecialchars($user['name']) ?></span>
        <span class="edit-hint">✎</span>
      </div>
    </div>

    <?php if ($order && $order['status'] === 'active'): ?>
    <div class="timer-block">
      <div class="timer-dot"></div>
      <span class="timer-label"><?= htmlspecialchars(t('front.timer.running')) ?><?php if ($order['deadline']): ?> <?= htmlspecialchars(t('front.timer.until')) ?> <?= date('H:i', strtotime($order['deadline'])) ?><?php endif; ?></span>
      <?php if ($secondsLeft !== null): ?>
      <div class="progress-wrap"><div class="progress-fill" id="progressFill"></div></div>
      <div class="timer-count" id="timerDisplay">--:--</div>
      <?php else: ?>
      <div style="flex:1"></div>
      <div class="timer-count" style="color:var(--success)"><?= htmlspecialchars(t('front.timer.unlimited')) ?></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="main">

    <?php if (!$order || $order['status'] !== 'active'): ?>
    <?php if ($lastOrder): ?>
    <div class="no-order-hint">
      <div class="no-order-hint-icon">&#x1F550;</div>
      <div>
        <strong><?= htmlspecialchars(t('front.no_order.title')) ?></strong>
        <span><?= htmlspecialchars(t('front.no_order.closed_at', ['date' => date('d.m.', strtotime($lastOrder['closed_at'])), 'time' => date('H:i', strtotime($lastOrder['closed_at']))])) ?></span>
      </div>
    </div>
    <div class="section-title" style="opacity:.4;margin-bottom:6px"><?= htmlspecialchars(t('front.last_order.title')) ?></div>
    <div class="section-sub" style="opacity:.4"><?= htmlspecialchars(t('front.last_order.subtitle')) ?></div>
    <div class="product-grid product-grid-frozen">
      <?php foreach ($lastProducts as $lp):
        $isMine = $lastPrevItem && (int)$lastPrevItem['product_id'] === (int)$lp['product_id'];
        $cnt = 0; foreach ($lastOrderItems as $lr) { if ((int)$lr['product_id']===(int)$lp['product_id']) $cnt++; }
      ?>
      <div class="product-card frozen <?= $isMine ? 'frozen-mine' : '' ?>">
        <?php if ($isMine): ?><div class="check-badge">&#x2713;</div><?php endif; ?>
        <div class="frozen-count"><?= $cnt ?>&times;</div>
        <div class="product-img">
          <?php if (!empty($lp['image_url'])): ?>
            <img src="<?= htmlspecialchars($lp['image_url']) ?>"
                 alt="<?= htmlspecialchars($lp['product_name']) ?>" loading="lazy"
                 onerror="this.parentElement.innerHTML='<div class=\'product-img-fallback\'>&#x1F355;</div>'" />
          <?php else: ?><div class="product-img-fallback">&#x1F355;</div><?php endif; ?>
        </div>
        <div class="product-info">
          <div class="product-name"><?= htmlspecialchars($lp['product_name']) ?></div>
          <?php if (!empty($lp['description'])): ?>
          <div class="product-desc"><?= htmlspecialchars($lp['description']) ?></div>
          <?php endif; ?>
          <?php if ($lp['price'] > 0): ?>
          <div class="product-price">&euro; <?= number_format((float)$lp['price'],2,',','.') ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="no-order">
      <div class="no-icon">&#x1F550;</div>
      <h2><?= htmlspecialchars(t('front.no_order.title')) ?></h2>
      <p><?= htmlspecialchars(t('front.no_order.body1')) ?><br><?= htmlspecialchars(t('front.no_order.body2')) ?></p>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="success-banner <?= $prevItem ? 'show' : '' ?>" id="successBanner">
      <div class="s-icon">✅</div>
      <h3><?= htmlspecialchars(t('front.success.title')) ?></h3>
      <p><?= htmlspecialchars(t('front.success.body')) ?></p>
    </div>

    <div class="section-title"><?= htmlspecialchars(t('front.order.question', ['name' => explode(' ', $user['name'])[0]])) ?></div>
    <div class="section-sub"><?= htmlspecialchars(t('front.order.subtitle')) ?></div>

    <div class="product-grid">
      <?php foreach ($products as $p):
        $sel = $prevItem && (int)$prevItem['product_id'] === (int)$p['id'];
      ?>
      <div class="product-card <?= $sel ? 'selected' : '' ?>"
           onclick="selectProduct(this)" data-id="<?= (int)$p['id'] ?>">
        <div class="check-badge">✓</div>
        <div class="product-img">
          <?php if (!empty($p['image_url'])): ?>
            <img src="<?= htmlspecialchars($p['image_url']) ?>"
                 alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy"
                 onerror="this.parentElement.innerHTML='<div class=\'product-img-fallback\'>🍕</div>'" />
          <?php else: ?>
            <div class="product-img-fallback">🍕</div>
          <?php endif; ?>
        </div>
        <div class="product-info">
          <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
          <?php if (!empty($p['description'])): ?>
          <div class="product-desc"><?= htmlspecialchars($p['description']) ?></div>
          <?php endif; ?>
          <?php if ($p['price'] > 0): ?>
          <div class="product-price">€ <?= number_format((float)$p['price'], 2, ',', '.') ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="comment-block">
      <label><?= htmlspecialchars(t('front.comment.label')) ?> <span style="font-weight:300"><?= htmlspecialchars(t('common.optional')) ?></span></label>
      <textarea id="commentField"
                placeholder="<?= htmlspecialchars(t('front.comment.placeholder')) ?>"><?= htmlspecialchars($prevItem['comment'] ?? '') ?></textarea>
    </div>

    <button class="btn-submit" id="submitBtn" onclick="submitOrder()">
      <span>🍕</span> <?= htmlspecialchars($prevItem ? t('front.submit.update') : t('front.submit.send')) ?>
    </button>
    <?php endif; ?>

  </div>
</div>

<script>
const i18n = <?= json_encode([
  'saving'        => t('front.js.saving'),
  'saved'         => t('front.js.saved'),
  'update'        => t('front.submit.update'),
  'retry'         => t('front.js.retry'),
  'save_error'    => t('front.js.save_error'),
  'network_error' => t('front.js.network_error'),
  'expired'       => t('front.js.expired'),
  'order_closed'  => t('front.js.order_closed'),
  'error_generic' => t('front.js.error_generic'),
  'save'          => t('common.save'),
], JSON_UNESCAPED_UNICODE) ?>;

let selectedCard = document.querySelector('.product-card.selected');
let secondsLeft  = <?= $secondsLeft ?? 'null' ?>;
const totalSeconds = <?= $totalSeconds ?? 'null' ?>;

// ── Produktauswahl ────────────────────────────────────────────
function selectProduct(card) {
  if (selectedCard) selectedCard.classList.remove('selected');
  card.classList.add('selected');
  selectedCard = card;
}

// ── Bestellung absenden ───────────────────────────────────────
function submitOrder() {
  if (!selectedCard) return;
  const btn     = document.getElementById('submitBtn');
  const comment = document.getElementById('commentField').value;
  btn.disabled  = true;
  btn.innerHTML = '⏳ ' + i18n.saving;
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({ action: 'save_order', product_id: selectedCard.dataset.id, comment })
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      const b = document.getElementById('successBanner');
      if (b) { b.classList.add('show'); b.scrollIntoView({behavior:'smooth', block:'nearest'}); }
      btn.innerHTML = i18n.saved;
      btn.style.background = 'linear-gradient(135deg,#4caf7d,#2e9e65)';
      btn.style.boxShadow  = '0 6px 24px rgba(76,175,125,.35)';
      setTimeout(() => {
        btn.disabled = false;
        btn.innerHTML = '<span>🍕</span> ' + i18n.update;
        btn.style.background = btn.style.boxShadow = '';
      }, 2000);
    } else {
      btn.disabled = false;
      btn.innerHTML = '<span>🍕</span> ' + i18n.retry;
      alert(data.error || i18n.save_error);
    }
  })
  .catch(() => { btn.disabled = false; btn.innerHTML = '<span>🍕</span> ' + i18n.network_error; });
}

// ── Timer ─────────────────────────────────────────────────────
if (secondsLeft !== null) {
  function tick() {
    if (secondsLeft < 0) secondsLeft = 0;
    const h = Math.floor(secondsLeft / 3600);
    const m = Math.floor((secondsLeft % 3600) / 60);
    const s = secondsLeft % 60;
    const d = document.getElementById('timerDisplay');
    const f = document.getElementById('progressFill');
    if (d) {
      d.textContent = h > 0
        ? String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')
        : String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
    }
    if (f) f.style.width = (secondsLeft / totalSeconds * 100) + '%';
    if (secondsLeft <= 0) {
      if (d) { d.textContent = i18n.expired; d.style.color = '#e05555'; }
      const btn = document.getElementById('submitBtn');
      if (btn) { btn.disabled = true; btn.innerHTML = i18n.order_closed; }
      return;
    }
    secondsLeft--;
    setTimeout(tick, 1000);
  }
  tick();
}

// ── Namen ändern ──────────────────────────────────────────────
function openRename() {
  const current = document.getElementById('userName').textContent;
  document.getElementById('renameInput').value = current;
  document.getElementById('renameModal').classList.add('open');
  setTimeout(() => document.getElementById('renameInput').select(), 50);
}

function closeRename() {
  document.getElementById('renameModal').classList.remove('open');
}

function saveRename() {
  const input = document.getElementById('renameInput');
  const name  = input.value.trim();
  if (!name) { input.focus(); return; }
  const btn = document.getElementById('renameSaveBtn');
  btn.disabled = true;
  btn.textContent = '…';
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({ action: 'rename', name })
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      document.getElementById('userName').textContent = data.name;
      document.getElementById('userAvatar').textContent = data.name.charAt(0).toUpperCase();
      closeRename();
    } else {
      alert(data.error || i18n.error_generic);
    }
    btn.disabled = false;
    btn.textContent = i18n.save;
  })
  .catch(() => { btn.disabled = false; btn.textContent = i18n.save; });
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeRename();
});
</script>

<!-- Rename Modal -->
<div class="rename-modal-overlay" id="renameModal" onclick="if(event.target===this)closeRename()">
  <div class="rename-modal">
    <h3><?= htmlspecialchars(t('front.rename.title')) ?></h3>
    <input type="text" id="renameInput" placeholder="<?= htmlspecialchars(t('common.name_placeholder')) ?>" maxlength="80"
           onkeydown="if(event.key==='Enter')saveRename()" />
    <div class="rename-info">ℹ️ <span><?= htmlspecialchars(t('front.rename.ip_info', ['ip' => $ip])) ?></span></div>
    <div class="rename-modal-footer">
      <button class="btn-modal-cancel" onclick="closeRename()"><?= htmlspecialchars(t('common.cancel')) ?></button>
      <button class="btn-modal-save" id="renameSaveBtn" onclick="saveRename()"><?= htmlspecialchars(t('common.save')) ?></button>
    </div>
  </div>
</div>
<?php endif; ?>
</body>
</html>
