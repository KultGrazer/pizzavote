<?php
// ============================================================
//  PizzaVote – Backend / Admin
//  Datei: backend/index.php
// ============================================================
require_once __DIR__ . '/../config.php';

session_start();

// ── Auth ─────────────────────────────────────────────────────
if (isset($_POST['pw'])) {
    if ($_POST['pw'] === ADMIN_PASSWORD) $_SESSION['admin'] = true;
    else $loginError = true;
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: .'); exit; }
if (empty($_SESSION['admin'])) { showLogin($loginError ?? false); exit; }

$db  = getDB();
$msg = '';
$msgType = 'ok';

// ── Aktionen ─────────────────────────────────────────────────

// Neue Bestellung
if (isset($_POST['action']) && $_POST['action'] === 'new_order') {
    $title    = mb_substr(trim($_POST['title']), 0, 150) ?: t('admin.orders.default_title');
    $deadline = null;
    if (!empty($_POST['deadline'])) {
        $ts = strtotime($_POST['deadline']);
        if ($ts && $ts > time()) {
            $deadline = date('Y-m-d H:i:s', $ts);
        } else {
            $msg = t('admin.msg.invalid_deadline');
            $msgType = 'warn';
            goto skip_new_order;
        }
    }
    $db->exec("UPDATE orders SET status='closed', closed_at=datetime('now','localtime') WHERE status='active'");
    $db->prepare("INSERT INTO orders (title, status, deadline) VALUES (?, 'active', ?)")
       ->execute([$title, $deadline]);
    $msg = $deadline
        ? t('admin.msg.order_started_until', ['deadline' => date('d.m.Y H:i', strtotime($deadline))])
        : t('admin.msg.order_started');
    skip_new_order:;
}

// Bestellung bearbeiten
if (isset($_POST['action']) && $_POST['action'] === 'edit_order') {
    $id       = (int)$_POST['order_id'];
    $title    = mb_substr(trim($_POST['title']), 0, 150) ?: t('admin.orders.default_title');
    $deadline = null;
    if (!empty($_POST['deadline'])) {
        $ts = strtotime($_POST['deadline']);
        if ($ts) $deadline = date('Y-m-d H:i:s', $ts);
    }
    $db->prepare("UPDATE orders SET title=?, deadline=? WHERE id=?")
       ->execute([$title, $deadline, $id]);
    $msg = t('admin.msg.order_updated');
}

// Bestellung abschließen
if (isset($_POST['action']) && $_POST['action'] === 'close_order') {
    $db->prepare("UPDATE orders SET status='closed', closed_at=datetime('now','localtime') WHERE id=?")
       ->execute([(int)$_POST['order_id']]);
    $msg = t('admin.msg.order_closed');
}

// Bestellung reaktivieren
if (isset($_POST['action']) && $_POST['action'] === 'reactivate_order') {
    // Andere aktive Bestellungen zuerst schließen
    $db->exec("UPDATE orders SET status='closed', closed_at=datetime('now','localtime') WHERE status='active'");
    $db->prepare("UPDATE orders SET status='active', closed_at=NULL WHERE id=?")
       ->execute([(int)$_POST['order_id']]);
    $msg = t('admin.msg.order_reactivated');
}

// Bestellung löschen
if (isset($_POST['action']) && $_POST['action'] === 'delete_order') {
    $db->prepare("DELETE FROM orders WHERE id=?")->execute([(int)$_POST['order_id']]);
    $msg = t('admin.msg.order_deleted');
}

// Bild-Upload für ein Produkt verarbeiten. Liefert [image_url, error].
// Ohne hochgeladene Datei bleibt der bisherige/eingegebene Pfad unverändert.
function handleProductImageUpload(int $productId, string $fallbackImg): array {
    if (empty($_FILES['p_img_file']) || $_FILES['p_img_file']['error'] === UPLOAD_ERR_NO_FILE) {
        return [$fallbackImg, null];
    }
    $file = $_FILES['p_img_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE  => t('admin.upload.too_big_ini', ['max' => ini_get('upload_max_filesize')]),
            UPLOAD_ERR_FORM_SIZE => t('admin.upload.too_big'),
            UPLOAD_ERR_PARTIAL   => t('admin.upload.partial'),
        ];
        return [$fallbackImg, $errors[$file['error']] ?? t('admin.upload.failed_generic')];
    }

    $loaders = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_GIF  => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !isset($loaders[$info[2]])) {
        return [$fallbackImg, t('admin.upload.invalid_type')];
    }

    $imgDir = __DIR__ . '/../img';
    if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
    if (!is_writable($imgDir)) {
        return [$fallbackImg, t('admin.upload.dir_not_writable')];
    }

    $src = @$loaders[$info[2]]($file['tmp_name']);
    if (!$src) {
        return [$fallbackImg, t('admin.upload.read_failed')];
    }

    // Auf Zielverhältnis 4:3 mittig zuschneiden, dann auf 800x600 skalieren
    // (gleiches Muster wie save_as_jpg() in generate_pizza_images.py).
    $targetW = 800;
    $targetH = 600;
    $w = imagesx($src);
    $h = imagesy($src);
    $targetRatio = $targetW / $targetH;
    $srcRatio = $w / $h;
    if ($srcRatio > $targetRatio) {
        $cropW = (int)round($h * $targetRatio);
        $cropH = $h;
        $cropX = (int)(($w - $cropW) / 2);
        $cropY = 0;
    } else {
        $cropW = $w;
        $cropH = (int)round($w / $targetRatio);
        $cropX = 0;
        $cropY = (int)(($h - $cropH) / 2);
    }

    $dst = imagecreatetruecolor($targetW, $targetH);
    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);
    imagedestroy($src);

    $filename = 'product_' . $productId . '_' . time() . '.jpg';
    $ok = imagejpeg($dst, $imgDir . '/' . $filename, 85);
    imagedestroy($dst);
    if (!$ok) {
        return [$fallbackImg, t('admin.upload.save_failed')];
    }
    return ['img/' . $filename, null];
}

// Produkt anlegen
if (isset($_POST['action']) && $_POST['action'] === 'add_product') {
    $name  = mb_substr(trim($_POST['p_name']),  0, 120);
    $desc  = mb_substr(trim($_POST['p_desc']),  0, 255);
    $price = round((float)str_replace(',','.', $_POST['p_price']), 2);
    $img   = mb_substr(trim($_POST['p_img']),   0, 500);
    $sort  = (int)$_POST['p_sort'];
    if ($name !== '') {
        $db->prepare('INSERT INTO products (name,description,price,image_url,sort_order) VALUES (?,?,?,?,?)')
           ->execute([$name, $desc, $price, $img, $sort]);
        $msg = t('admin.msg.product_created');
    }
}

// Produkt bearbeiten
if (isset($_POST['action']) && $_POST['action'] === 'edit_product') {
    $id    = (int)$_POST['product_id'];
    $name  = mb_substr(trim($_POST['p_name']),  0, 120);
    $desc  = mb_substr(trim($_POST['p_desc']),  0, 255);
    $price = round((float)str_replace(',','.', $_POST['p_price']), 2);
    $sort  = (int)$_POST['p_sort'];
    $imgFallback = mb_substr(trim($_POST['p_img']), 0, 500);
    [$img, $imgError] = handleProductImageUpload($id, $imgFallback);
    if ($name !== '') {
        $db->prepare('UPDATE products SET name=?,description=?,price=?,image_url=?,sort_order=? WHERE id=?')
           ->execute([$name, $desc, $price, $img, $sort, $id]);
        if ($imgError) {
            $msg = t('admin.msg.product_updated_img_error', ['error' => $imgError]);
            $msgType = 'warn';
        } else {
            $msg = t('admin.msg.product_updated');
        }
    }
}

// Produkt toggle aktiv
if (isset($_POST['action']) && $_POST['action'] === 'toggle_product') {
    $db->prepare('UPDATE products SET active = 1 - active WHERE id = ?')
       ->execute([(int)$_POST['product_id']]);
    header('Location: ?page=products'); exit;
}

// Produkt löschen
if (isset($_POST['action']) && $_POST['action'] === 'delete_product') {
    $db->prepare('DELETE FROM products WHERE id = ?')->execute([(int)$_POST['product_id']]);
    $msg = t('admin.msg.product_deleted');
}

// ── Daten laden ───────────────────────────────────────────────
$activeOrder = $db->query(
    "SELECT * FROM orders WHERE status='active' ORDER BY created_at DESC LIMIT 1"
)->fetch();

$recentOrders = $db->query(
    "SELECT * FROM orders ORDER BY created_at DESC LIMIT 20"
)->fetchAll();

$products = $db->query(
    'SELECT * FROM products ORDER BY sort_order, id'
)->fetchAll();

$viewOrderId = (int)($_GET['order'] ?? ($activeOrder['id'] ?? 0));
$orderItems  = [];
$orderDetail = null;
if ($viewOrderId) {
    $od = $db->prepare('SELECT * FROM orders WHERE id=?');
    $od->execute([$viewOrderId]);
    $orderDetail = $od->fetch();
    if ($orderDetail) {
        $oi = $db->prepare('
            SELECT oi.*, u.name AS user_name, u.ip AS user_ip, p.name AS product_name, p.price
            FROM order_items oi
            JOIN users    u ON u.id = oi.user_id
            JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
            ORDER BY p.name, u.name
        ');
        $oi->execute([$viewOrderId]);
        $orderItems = $oi->fetchAll();
    }
}

$summary = [];
foreach ($orderItems as $item) {
    $key = $item['product_name'];
    if (!isset($summary[$key])) $summary[$key] = ['count'=>0,'price'=>$item['price'],'users'=>[]];
    $summary[$key]['count']++;
    $summary[$key]['users'][] = $item['user_name'].($item['comment']?' ('.$item['comment'].')':'');
}

// ── Login-Screen ──────────────────────────────────────────────
function showLogin(bool $error): void { ?>
<!DOCTYPE html><html lang="<?= APP_LANG ?>"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(t('admin.login.title')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#0e0e0f;color:#f0ede8;font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center}
  .card{background:#171718;border:1px solid #2a2a2d;border-radius:20px;padding:44px 40px;width:min(400px,90vw);text-align:center}
  h1{font-size:1.5rem;margin-bottom:24px}
  input{width:100%;background:#1f1f21;border:1.5px solid #2a2a2d;border-radius:10px;color:#f0ede8;font-family:inherit;font-size:1rem;padding:13px 18px;outline:none;margin-bottom:14px}
  input:focus{border-color:#e8651a}
  button{width:100%;background:#e8651a;border:none;border-radius:10px;color:#fff;font-family:inherit;font-size:1rem;font-weight:500;padding:14px;cursor:pointer}
  button:hover{background:#f07328}
  .err{color:#e05555;font-size:.88rem;margin-bottom:14px}
</style></head><body>
<div class="card">
  <h1>🍕 <?= htmlspecialchars(t('admin.brand')) ?></h1>
  <?php if ($error): ?><div class="err"><?= htmlspecialchars(t('admin.login.wrong_password')) ?></div><?php endif; ?>
  <form method="post">
    <input type="password" name="pw" placeholder="<?= htmlspecialchars(t('admin.login.password_placeholder')) ?>" autofocus required />
    <button type="submit"><?= htmlspecialchars(t('admin.login.submit')) ?></button>
  </form>
</div>
</body></html>
<?php }
?>
<!DOCTYPE html>
<html lang="<?= APP_LANG ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars(t('admin.page_title')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:#0e0e0f; --surface:#171718; --surface2:#1f1f21;
    --border:#2a2a2d; --accent:#e8651a; --accent2:#f0a500;
    --text:#f0ede8; --muted:#7a7875; --success:#4caf7d; --danger:#e05555;
    --r:12px;
  }
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh;color-scheme:dark}

  .layout{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
  .sidebar{background:var(--surface);border-right:1px solid var(--border);padding:28px 20px;display:flex;flex-direction:column;gap:6px;position:sticky;top:0;height:100vh}
  .sidebar-logo{font-family:'Playfair Display',serif;font-size:1.3rem;font-weight:900;padding:0 8px 20px;border-bottom:1px solid var(--border);margin-bottom:14px}
  .nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--r);font-size:.9rem;color:var(--muted);text-decoration:none;transition:background .15s,color .15s}
  .nav-item:hover,.nav-item.active{background:var(--surface2);color:var(--text)}
  .nav-item .icon{font-size:1rem;width:20px;text-align:center}
  .sidebar-bottom{margin-top:auto}
  .logout{color:var(--danger)!important}

  .content{padding:36px 40px;overflow-y:auto}
  .page-title{font-family:'Playfair Display',serif;font-size:1.8rem;font-weight:900;margin-bottom:28px}

  .card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:24px;margin-bottom:24px}
  .card-title{font-size:.8rem;font-weight:500;margin-bottom:18px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}

  .badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:50px;font-size:.78rem;font-weight:500}
  .badge-active{background:rgba(76,175,125,.15);color:var(--success)}
  .badge-closed{background:rgba(122,120,117,.15);color:var(--muted)}

  .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
  .form-row.full{grid-template-columns:1fr}
  .form-group{display:flex;flex-direction:column;gap:6px}
  label{font-size:.82rem;color:var(--muted)}
  input[type=text],input[type=number],input[type=password],input[type=datetime-local],textarea,select{
    background:var(--surface2);border:1.5px solid var(--border);border-radius:var(--r);
    color:var(--text);font-family:'DM Sans',sans-serif;font-size:.9rem;
    padding:10px 14px;outline:none;transition:border-color .2s;width:100%
  }
  input:focus,textarea:focus{border-color:var(--accent)}
  input::placeholder{color:var(--muted)}

  .btn{display:inline-flex;align-items:center;gap:6px;border:none;border-radius:var(--r);cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:500;padding:10px 18px;transition:opacity .2s,transform .1s;text-decoration:none;white-space:nowrap}
  .btn:hover{opacity:.85;transform:translateY(-1px)}
  .btn-accent{background:var(--accent);color:#fff}
  .btn-success{background:var(--success);color:#fff}
  .btn-danger{background:var(--danger);color:#fff}
  .btn-ghost{background:var(--surface2);color:var(--text);border:1px solid var(--border)}
  .btn-sm{padding:6px 12px;font-size:.82rem}
  .btn-icon{padding:6px 9px}

  .table-wrap{overflow-x:auto}
  table{width:100%;border-collapse:collapse;font-size:.88rem}
  th{text-align:left;padding:10px 14px;border-bottom:1px solid var(--border);color:var(--muted);font-weight:500;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em}
  td{padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
  tr:last-child td{border-bottom:none}
  tr:hover td{background:rgba(255,255,255,.02)}
  .actions{display:flex;gap:6px;align-items:center}

  .msg{border-radius:var(--r);padding:12px 18px;margin-bottom:22px;font-size:.9rem}
  .msg-ok{background:rgba(76,175,125,.1);border:1px solid rgba(76,175,125,.25)}
  .msg-warn{background:rgba(224,85,85,.1);border:1px solid rgba(224,85,85,.25)}

  .summary-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;margin-bottom:24px}
  .summary-card{background:var(--surface2);border:1px solid var(--border);border-radius:var(--r);padding:16px 18px}
  .summary-count{font-family:'Playfair Display',serif;font-size:2rem;font-weight:900;color:var(--accent)}
  .summary-name{font-size:.9rem;font-weight:500;margin:2px 0}
  .summary-total{font-size:.8rem;color:var(--accent2)}
  .summary-users{font-size:.78rem;color:var(--muted);margin-top:6px;line-height:1.5}

  /* Modal */
  .modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.75);
    display:flex;align-items:center;justify-content:center;
    z-index:1000;opacity:0;pointer-events:none;transition:opacity .2s;
  }
  .modal-overlay.open{opacity:1;pointer-events:all}
  .modal{
    background:var(--surface);border:1px solid var(--border);border-radius:20px;
    padding:32px;width:min(540px,92vw);max-height:90vh;overflow-y:auto;
    transform:translateY(20px);transition:transform .25s;
    box-shadow:0 40px 80px rgba(0,0,0,.7);
  }
  .modal-overlay.open .modal{transform:translateY(0)}
  .modal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
  .modal-title{font-family:'Playfair Display',serif;font-size:1.3rem;font-weight:700}
  .modal-close{background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--muted);cursor:pointer;font-size:1rem;padding:5px 10px;line-height:1.4;transition:color .15s}
  .modal-close:hover{color:var(--text)}
  .modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:20px;border-top:1px solid var(--border)}

  @media print {
    .sidebar,.no-print{display:none!important}
    .layout{grid-template-columns:1fr}
    .content{padding:0}
    body{background:#fff;color:#000}
  }
  @media(max-width:768px){
    .layout{grid-template-columns:1fr}
    .sidebar{display:none}
    .content{padding:20px 16px}
    .form-row{grid-template-columns:1fr}
  }
</style>
</head>
<body>
<div class="layout">

  <aside class="sidebar">
    <div class="sidebar-logo">🍕 <?= htmlspecialchars(t('admin.brand')) ?></div>
    <a class="nav-item <?= !isset($_GET['page'])||$_GET['page']==='orders'?'active':'' ?>" href="?page=orders">
      <span class="icon">📋</span> <?= htmlspecialchars(t('admin.nav.orders')) ?>
    </a>
    <a class="nav-item <?= ($_GET['page']??'')==='products'?'active':'' ?>" href="?page=products">
      <span class="icon">🍕</span> <?= htmlspecialchars(t('admin.nav.products')) ?>
    </a>
    <div class="sidebar-bottom">
      <a class="nav-item logout" href="?logout=1"><span class="icon">🚪</span> <?= htmlspecialchars(t('admin.nav.logout')) ?></a>
    </div>
  </aside>

  <main class="content">

    <?php if ($msg): ?>
    <div class="msg msg-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php $page = $_GET['page'] ?? 'orders'; ?>

    <?php if ($page === 'orders'): ?>
    <!-- ════════════════ BESTELLUNGEN ════════════════ -->
    <div class="page-title"><?= htmlspecialchars(t('admin.nav.orders')) ?></div>

    <div class="card no-print">
      <div class="card-title"><?= htmlspecialchars(t('admin.orders.new_title')) ?></div>
      <form method="post">
        <input type="hidden" name="action" value="new_order" />
        <div class="form-row">
          <div class="form-group">
            <label><?= htmlspecialchars(t('admin.orders.label_title')) ?></label>
            <input type="text" name="title" value="<?= htmlspecialchars(t('admin.orders.default_new_title')) ?>" maxlength="150" />
          </div>
          <div class="form-group">
            <label><?= htmlspecialchars(t('admin.orders.label_deadline')) ?></label>
            <input type="datetime-local" name="deadline" id="deadlineInput" />
          </div>
        </div>
        <button type="submit" class="btn btn-accent"><?= htmlspecialchars(t('admin.orders.start_btn')) ?></button>
        <?php if ($activeOrder): ?>
        &nbsp;<small style="color:var(--muted)"><?= htmlspecialchars(t('admin.orders.start_warning')) ?></small>
        <?php endif; ?>
      </form>
    </div>
    <script>
      (function(){
        var el=document.getElementById('deadlineInput'); if(!el) return;
        var d=new Date();
        d.setMinutes(0, 0, 0);          // auf volle Stunde abrunden
        d.setHours(d.getHours() + 2);   // +1h = nächste volle Stunde, +1 mehr = aufgerundet
        // Lokale Zeit als datetime-local string (nicht UTC)
        var pad = function(n){ return String(n).padStart(2,'0'); };
        el.value = d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())
                  +'T'+pad(d.getHours())+':00';
      })();
    </script>

    <?php
    // Zeige entweder aktive Bestellung oder per ?order= gewählte Bestellung
    $displayOrder = $activeOrder;
    if ($viewOrderId && $orderDetail && (!$activeOrder || $orderDetail['id'] != $activeOrder['id'])) {
        $displayOrder = $orderDetail;
    }
    ?>
    <?php if ($displayOrder): ?>
    <div class="card">
      <div class="card-title">
        <?= $displayOrder['status'] === 'active' ? htmlspecialchars(t('admin.orders.status_active')) : htmlspecialchars(t('admin.orders.status_closed')) ?>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
        <div>
          <strong style="font-size:1.1rem"><?= htmlspecialchars($displayOrder['title']) ?></strong>
          <span class="badge badge-<?= $displayOrder['status'] === 'active' ? 'active' : 'closed' ?>" style="margin-left:10px">
            <?= $displayOrder['status'] === 'active' ? htmlspecialchars(t('admin.orders.badge_active')) : htmlspecialchars(t('admin.orders.badge_closed')) ?>
          </span><br>
          <small style="color:var(--muted)">
            <?= htmlspecialchars(t('admin.orders.deadline_label')) ?> <?= $displayOrder['deadline'] ? date('d.m.Y H:i', strtotime($displayOrder['deadline'])) : '–' ?>
            &nbsp;·&nbsp; <?= htmlspecialchars(t(count($orderItems) == 1 ? 'admin.orders.count' : 'admin.orders.count_plural', ['n' => count($orderItems)])) ?>
          </small>
        </div>
        <div class="actions no-print">
          <button class="btn btn-ghost btn-sm" onclick="window.print()"><?= htmlspecialchars(t('admin.orders.print_btn')) ?></button>
          <button class="btn btn-ghost btn-sm" onclick="openEditOrder(
            <?= (int)$displayOrder['id'] ?>,
            '<?= htmlspecialchars(addslashes($displayOrder['title'])) ?>',
            '<?= $displayOrder['deadline'] ? date('Y-m-d\TH:i', strtotime($displayOrder['deadline'])) : '' ?>'
          )">✏️ <?= htmlspecialchars(t('common.edit')) ?></button>
          <?php if ($displayOrder['status'] === 'active'): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= addslashes(t('admin.confirm.close_order')) ?>')">
            <input type="hidden" name="action" value="close_order" />
            <input type="hidden" name="order_id" value="<?= (int)$displayOrder['id'] ?>" />
            <button type="submit" class="btn btn-success btn-sm"><?= htmlspecialchars(t('admin.orders.close_btn')) ?></button>
          </form>
          <?php else: ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= addslashes(t('admin.confirm.reactivate_order')) ?>')">
            <input type="hidden" name="action" value="reactivate_order" />
            <input type="hidden" name="order_id" value="<?= (int)$displayOrder['id'] ?>" />
            <button type="submit" class="btn btn-success btn-sm"><?= htmlspecialchars(t('admin.orders.reactivate_btn')) ?></button>
          </form>
          <?php endif; ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= addslashes(t('admin.confirm.delete_order')) ?>')">
            <input type="hidden" name="action" value="delete_order" />
            <input type="hidden" name="order_id" value="<?= (int)$displayOrder['id'] ?>" />
            <button type="submit" class="btn btn-danger btn-sm btn-icon">🗑</button>
          </form>
        </div>
      </div>

      <?php if (!empty($summary)): ?>
      <div class="card-title"><?= htmlspecialchars(t('admin.orders.summary_title')) ?></div>
      <div class="summary-grid">
        <?php foreach ($summary as $pName => $s): ?>
        <div class="summary-card">
          <div class="summary-count"><?= $s['count'] ?>×</div>
          <div class="summary-name"><?= htmlspecialchars($pName) ?></div>
          <div class="summary-total">€ <?= number_format($s['count']*$s['price'],2,',','.') ?></div>
          <div class="summary-users"><?= implode('<br>', array_map('htmlspecialchars', $s['users'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="card-title" style="margin-top:8px"><?= htmlspecialchars(t('admin.orders.items_table_title')) ?></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= htmlspecialchars(t('admin.orders.th_person')) ?></th><th class="no-print"><?= htmlspecialchars(t('admin.orders.th_ip')) ?></th><th><?= htmlspecialchars(t('admin.orders.th_product')) ?></th><th><?= htmlspecialchars(t('admin.orders.th_comment')) ?></th><th><?= htmlspecialchars(t('admin.orders.th_time')) ?></th><th><?= htmlspecialchars(t('admin.orders.th_price')) ?></th></tr></thead>
          <tbody>
            <?php foreach ($orderItems as $item): ?>
            <tr>
              <td><?= htmlspecialchars($item['user_name']) ?></td>
              <td class="no-print" style="color:var(--muted);font-size:.8rem"><?= htmlspecialchars($item['user_ip']) ?></td>
              <td><?= htmlspecialchars($item['product_name']) ?></td>
              <td><?= htmlspecialchars($item['comment']?:'–') ?></td>
              <td style="color:var(--muted);font-size:.8rem"><?= date('H:i', strtotime($item['updated_at'])) ?></td>
              <td>€ <?= number_format((float)$item['price'],2,',','.') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td style="font-weight:500;padding-top:14px"><?= htmlspecialchars(t('common.total')) ?></td>
              <td class="no-print"></td>
              <td colspan="2"></td>
              <td></td>
              <td style="font-weight:700;color:var(--accent2);padding-top:14px">
                € <?= number_format(array_sum(array_column($orderItems,'price')),2,',','.') ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php else: ?>
      <p style="color:var(--muted);font-size:.9rem"><?= htmlspecialchars(t('admin.orders.empty')) ?></p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($displayOrder && isset($_GET['print'])): ?>
    <script>window.addEventListener('load', () => window.print());</script>
    <?php endif; ?>

    <div class="card no-print">
      <div class="card-title"><?= htmlspecialchars(t('admin.orders.history_title', ['n' => count($recentOrders)])) ?></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= htmlspecialchars(t('admin.orders.th_order_title')) ?></th><th><?= htmlspecialchars(t('admin.orders.th_status')) ?></th><th><?= htmlspecialchars(t('admin.orders.th_created')) ?></th><th><?= htmlspecialchars(t('admin.orders.th_deadline')) ?></th><th></th></tr></thead>
          <tbody>
            <?php foreach ($recentOrders as $o): ?>
            <tr>
              <td><strong><?= htmlspecialchars($o['title']) ?></strong></td>
              <td><span class="badge badge-<?= $o['status']==='active'?'active':'closed' ?>"><?= $o['status'] ?></span></td>
              <td style="color:var(--muted);font-size:.82rem"><?= date('d.m.Y H:i', strtotime($o['created_at'])) ?></td>
              <td style="color:var(--muted);font-size:.82rem"><?= $o['deadline'] ? date('d.m. H:i', strtotime($o['deadline'])) : '–' ?></td>
              <td>
                <div class="actions">
                  <a href="?page=orders&order=<?= (int)$o['id'] ?>" class="btn btn-ghost btn-sm">📋</a>
                  <a href="?page=orders&order=<?= (int)$o['id'] ?>&print=1" class="btn btn-ghost btn-sm btn-icon" title="<?= htmlspecialchars(t('admin.orders.print_btn')) ?>">🖨️</a>
                  <button class="btn btn-ghost btn-sm btn-icon" onclick="openEditOrder(
                    <?= (int)$o['id'] ?>,
                    '<?= htmlspecialchars(addslashes($o['title'])) ?>',
                    '<?= $o['deadline'] ? date('Y-m-d\TH:i', strtotime($o['deadline'])) : '' ?>'
                  )">✏️</button>
                  <?php if ($o['status'] !== 'active'): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('<?= addslashes(t('admin.confirm.reactivate_order')) ?>')">
                    <input type="hidden" name="action" value="reactivate_order" />
                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>" />
                    <button type="submit" class="btn btn-success btn-sm btn-icon" title="<?= htmlspecialchars(t('admin.orders.reactivate_tooltip')) ?>">▶</button>
                  </form>
                  <?php endif; ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('<?= addslashes(t('admin.confirm.delete_order')) ?>')">
                    <input type="hidden" name="action" value="delete_order" />
                    <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>" />
                    <button type="submit" class="btn btn-danger btn-sm btn-icon">🗑</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif ($page === 'products'): ?>
    <!-- ════════════════ PRODUKTE ════════════════ -->
    <div class="page-title"><?= htmlspecialchars(t('admin.nav.products')) ?></div>

    <div class="card">
      <div class="card-title"><?= htmlspecialchars(t('admin.products.new_title')) ?></div>
      <form method="post">
        <input type="hidden" name="action" value="add_product" />
        <div class="form-row">
          <div class="form-group">
            <label><?= htmlspecialchars(t('admin.products.label_name')) ?></label>
            <input type="text" name="p_name" placeholder="<?= htmlspecialchars(t('admin.products.name_placeholder')) ?>" maxlength="120" required />
          </div>
          <div class="form-group">
            <label><?= htmlspecialchars(t('admin.products.label_price')) ?></label>
            <input type="text" name="p_price" placeholder="9,50" />
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label><?= htmlspecialchars(t('admin.products.label_desc')) ?></label>
            <input type="text" name="p_desc" placeholder="<?= htmlspecialchars(t('admin.products.desc_placeholder')) ?>" maxlength="255" />
          </div>
          <div class="form-group">
            <label><?= htmlspecialchars(t('admin.products.label_sort')) ?></label>
            <input type="number" name="p_sort" value="<?= count($products)+1 ?>" min="0" />
          </div>
        </div>
        <div class="form-row full">
          <div class="form-group">
            <label><?= htmlspecialchars(t('admin.products.label_image')) ?></label>
            <input type="text" name="p_img" placeholder="<?= htmlspecialchars(t('admin.products.image_placeholder')) ?>" maxlength="500" />
          </div>
        </div>
        <button type="submit" class="btn btn-accent" style="margin-top:4px"><?= htmlspecialchars(t('admin.products.add_btn')) ?></button>
      </form>
    </div>

    <div class="card">
      <div class="card-title"><?= htmlspecialchars(t('admin.products.list_title', ['n' => count($products)])) ?></div>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= htmlspecialchars(t('admin.products.th_id')) ?></th><th><?= htmlspecialchars(t('admin.products.th_name')) ?></th><th><?= htmlspecialchars(t('admin.products.th_desc')) ?></th><th><?= htmlspecialchars(t('admin.products.th_price')) ?></th><th><?= htmlspecialchars(t('admin.products.th_status')) ?></th><th></th></tr></thead>
          <tbody>
            <?php foreach ($products as $p): ?>
            <tr style="<?= !$p['active']?'opacity:.45':'' ?>">
              <td style="color:var(--muted);font-size:.8rem"><?= (int)$p['id'] ?></td>
              <td>
                <strong><?= htmlspecialchars($p['name']) ?></strong>
                <?php if (!empty($p['image_url'])): ?>
                <br><small style="color:var(--muted);font-size:.75rem"><?= htmlspecialchars(mb_strimwidth($p['image_url'],0,50,'…')) ?></small>
                <?php endif; ?>
              </td>
              <td style="color:var(--muted);font-size:.82rem"><?= htmlspecialchars($p['description']) ?></td>
              <td>€ <?= number_format((float)$p['price'],2,',','.') ?></td>
              <td><span class="badge <?= $p['active']?'badge-active':'badge-closed' ?>"><?= $p['active']?htmlspecialchars(t('common.active')):htmlspecialchars(t('common.inactive')) ?></span></td>
              <td>
                <div class="actions">
                  <button class="btn btn-ghost btn-sm btn-icon" title="<?= htmlspecialchars(t('common.edit')) ?>" onclick="openEditProduct(
                    <?= (int)$p['id'] ?>,
                    '<?= htmlspecialchars(addslashes($p['name'])) ?>',
                    '<?= htmlspecialchars(addslashes($p['description'])) ?>',
                    '<?= number_format((float)$p['price'],2,'.','.') ?>',
                    '<?= htmlspecialchars(addslashes($p['image_url'])) ?>',
                    <?= (int)$p['sort_order'] ?>
                  )">✏️</button>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="toggle_product" />
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>" />
                    <button type="submit" class="btn btn-ghost btn-sm btn-icon" title="<?= $p['active']?htmlspecialchars(t('admin.products.hide_tooltip')):htmlspecialchars(t('admin.products.show_tooltip')) ?>">
                      <?= $p['active']?'🙈':'👁' ?>
                    </button>
                  </form>
                  <form method="post" style="display:inline" onsubmit="return confirm('<?= addslashes(t('admin.confirm.delete_product')) ?>')">
                    <input type="hidden" name="action" value="delete_product" />
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>" />
                    <button type="submit" class="btn btn-danger btn-sm btn-icon" title="<?= htmlspecialchars(t('common.delete')) ?>">🗑</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php endif; ?>
  </main>
</div>

<!-- ── MODAL: Bestellung bearbeiten ── -->
<div class="modal-overlay" id="modalOrder" onclick="if(event.target===this)closeModal('modalOrder')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><?= htmlspecialchars(t('admin.orders.edit_modal_title')) ?></div>
      <button class="modal-close" onclick="closeModal('modalOrder')">✕</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="edit_order" />
      <input type="hidden" name="order_id" id="editOrderId" />
      <div class="form-row full" style="margin-bottom:12px">
        <div class="form-group">
          <label><?= htmlspecialchars(t('admin.orders.label_title')) ?></label>
          <input type="text" name="title" id="editOrderTitle" maxlength="150" required />
        </div>
      </div>
      <div class="form-row full" style="margin-bottom:0">
        <div class="form-group">
          <label><?= htmlspecialchars(t('admin.orders.label_deadline')) ?></label>
          <input type="datetime-local" name="deadline" id="editOrderDeadline" />
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalOrder')"><?= htmlspecialchars(t('common.cancel')) ?></button>
        <button type="submit" class="btn btn-accent">💾 <?= htmlspecialchars(t('common.save')) ?></button>
      </div>
    </form>
  </div>
</div>

<!-- ── MODAL: Produkt bearbeiten ── -->
<div class="modal-overlay" id="modalProduct" onclick="if(event.target===this)closeModal('modalProduct')">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title"><?= htmlspecialchars(t('admin.products.edit_modal_title')) ?></div>
      <button class="modal-close" onclick="closeModal('modalProduct')">✕</button>
    </div>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="edit_product" />
      <input type="hidden" name="product_id" id="editProductId" />
      <div class="form-row">
        <div class="form-group">
          <label><?= htmlspecialchars(t('admin.products.label_name')) ?></label>
          <input type="text" name="p_name" id="editProductName" maxlength="120" required />
        </div>
        <div class="form-group">
          <label><?= htmlspecialchars(t('admin.products.label_price')) ?></label>
          <input type="text" name="p_price" id="editProductPrice" placeholder="9,50" />
        </div>
      </div>
      <div class="form-row full">
        <div class="form-group">
          <label><?= htmlspecialchars(t('admin.products.label_desc')) ?></label>
          <input type="text" name="p_desc" id="editProductDesc" maxlength="255" />
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label><?= htmlspecialchars(t('admin.products.label_image')) ?></label>
          <input type="text" name="p_img" id="editProductImg" maxlength="500" />
        </div>
        <div class="form-group">
          <label><?= htmlspecialchars(t('admin.products.label_sort')) ?></label>
          <input type="number" name="p_sort" id="editProductSort" min="0" />
        </div>
      </div>
      <div class="form-row full" style="margin-bottom:0">
        <div class="form-group">
          <label><?= htmlspecialchars(t('admin.products.upload_label')) ?></label>
          <input type="file" name="p_img_file" id="editProductImgFile" accept="image/jpeg,image/png,image/gif,image/webp" />
        </div>
      </div>
      <div class="form-group" style="margin-top:12px">
        <img id="editProductImgPreview" src="" alt="<?= htmlspecialchars(t('admin.products.preview_alt')) ?>" style="display:none;max-width:200px;max-height:150px;border-radius:10px;border:1px solid var(--border);object-fit:cover" />
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalProduct')"><?= htmlspecialchars(t('common.cancel')) ?></button>
        <button type="submit" class="btn btn-accent">💾 <?= htmlspecialchars(t('common.save')) ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditOrder(id, title, deadline) {
  document.getElementById('editOrderId').value       = id;
  document.getElementById('editOrderTitle').value    = title;
  document.getElementById('editOrderDeadline').value = deadline;
  document.getElementById('modalOrder').classList.add('open');
}

function resolveImgSrc(path) {
  if (!path) return '';
  if (/^([a-z][a-z0-9+.-]*:)?\/\//i.test(path) || path.startsWith('data:') || path.startsWith('/')) return path;
  return '../' + path;
}

function setProductImgPreview(src) {
  var preview = document.getElementById('editProductImgPreview');
  if (src) {
    preview.src = src;
    preview.style.display = 'block';
  } else {
    preview.removeAttribute('src');
    preview.style.display = 'none';
  }
}

function openEditProduct(id, name, desc, price, img, sort) {
  document.getElementById('editProductId').value    = id;
  document.getElementById('editProductName').value  = name;
  document.getElementById('editProductDesc').value  = desc;
  document.getElementById('editProductPrice').value = price;
  document.getElementById('editProductImg').value   = img;
  document.getElementById('editProductSort').value  = sort;
  document.getElementById('editProductImgFile').value = '';
  setProductImgPreview(resolveImgSrc(img));
  document.getElementById('modalProduct').classList.add('open');
}

document.getElementById('editProductImgFile').addEventListener('change', function (e) {
  var file = e.target.files[0];
  if (!file) {
    setProductImgPreview(resolveImgSrc(document.getElementById('editProductImg').value));
    return;
  }
  var reader = new FileReader();
  reader.onload = function (ev) { setProductImgPreview(ev.target.result); };
  reader.readAsDataURL(file);
});

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape')
    document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
});
</script>
</body>
</html>
