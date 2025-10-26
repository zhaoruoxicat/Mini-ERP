<?php
// products.php
// 产品管理：老板 + 库管&排产 可访问；无权限时使用统一的“权限不足”提示样式
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/inc/auth.php';

require_login(); // 必须登录，后续在页面内做角色检查（以便输出统一提示样式）

// ---------- 角色与权限 ----------
if (!defined('ROLE_BOSS'))  define('ROLE_BOSS',  'boss');
if (!defined('ROLE_OP'))    define('ROLE_OP',    'op');
if (!defined('ROLE_SALES')) define('ROLE_SALES', 'sales');

// 从 session 读取当前角色（与已记忆页面一致）
$sessionUser = $_SESSION['user'] ?? null;
$currRole = null;
if (is_array($sessionUser)) {
  $currRole = $sessionUser['role'] ?? ($_SESSION['role'] ?? null);
} elseif (is_object($sessionUser)) {
  $currRole = $sessionUser->role ?? ($_SESSION['role'] ?? null);
} else {
  $currRole = $_SESSION['role'] ?? null;
}
$hasAccess = in_array($currRole, [ROLE_BOSS, ROLE_OP], true); // 允许 老板 + 库管&排产

// 统一头部（无论权限与否，都先展示导航）
include __DIR__ . '/inc/header.php';

// 无权限：输出统一排版提示并结束
if (!$hasAccess) {
  ?>
  <style>
    .center-wrap { min-height: 60vh; display:flex; align-items:center; justify-content:center; }
    .big-deny   { font-size: 42px; font-weight: 800; color:#c92a2a; letter-spacing: .06em; }
    .sub-text   { color:#6b7280; margin-top:10px; }
  </style>
  <div class="center-wrap">
    <div class="text-center">
      <div class="big-deny">权限不足</div>
      <div class="sub-text">仅老板或库管&排产可访问产品管理页面</div>
    </div>
  </div>
  <?php
  include __DIR__ . '/inc/footer.php';
  exit;
}

// ---------- Utils ----------
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// ---------- 业务动作 ----------
$err = null;
$action = $_POST['action'] ?? null;

try {
  if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    $sku  = trim($_POST['sku'] ?? '') ?: null;
    $category_id = (int)($_POST['category_id'] ?? 0);
    $subcategory_id = (int)($_POST['subcategory_id'] ?? 0);
    if ($subcategory_id <= 0) $subcategory_id = null;
    $unit  = trim($_POST['unit'] ?? 'pcs');
    $price = $_POST['price'] === '' ? '0' : $_POST['price'];
    $brand = trim($_POST['brand'] ?? '') ?: null;
    $spec  = trim($_POST['spec'] ?? '') ?: null;
    $supplier = trim($_POST['supplier'] ?? '') ?: null;
    $note  = trim($_POST['note'] ?? '') ?: null;

    $color = trim($_POST['color'] ?? '') ?: null;
    $product_date = trim($_POST['product_date'] ?? '') ?: null;
    $weight = ($_POST['weight'] === '' ? null : $_POST['weight']);

    $init_stock   = (float)($_POST['init_stock'] ?? 0);
    $init_reserved= (float)($_POST['init_reserved'] ?? 0);

    if ($name !== '' && $category_id > 0) {
      $st = $pdo->prepare("INSERT INTO products
        (name,sku,category_id,subcategory_id,unit,price,brand,spec,supplier,color,product_date,weight,note)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $st->execute([$name,$sku,$category_id,$subcategory_id,$unit,$price,$brand,$spec,$supplier,$color,$product_date?:null,$weight,$note]);

      $newId = (int)$pdo->lastInsertId();

      if ($init_stock != 0.0) {
        $st2 = $pdo->prepare("INSERT INTO inventory_moves(product_id, move_type, quantity, note) VALUES(?,?,?,?)");
        $st2->execute([$newId, 'adjust', $init_stock, '初始化库存']);
      }
      if ($init_reserved > 0.0) {
        $st3 = $pdo->prepare("INSERT INTO reservations(product_id, quantity, customer, expected_date, status, note)
                              VALUES(?,?,?,?, 'pending', ?)");
        $st3->execute([$newId, $init_reserved, '初始化', null, '初始化预定']);
      }
    }
  } elseif ($action === 'edit') {
    // 你的编辑逻辑可按之前版本补充
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id>0) {
      $st = $pdo->prepare("DELETE FROM products WHERE id=?");
      $st->execute([$id]);
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

// ---------- 查询数据 ----------
$cats = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();
$subsAll = $pdo->query("SELECT * FROM subcategories ORDER BY sort_order ASC, id ASC")->fetchAll();
$subsByCat = [];
foreach ($subsAll as $row) {
  $subsByCat[(int)$row['category_id']][] = $row;
}

$sql = "SELECT p.*, c.name AS category_name, sc.name AS subcategory_name
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN subcategories sc ON p.subcategory_id = sc.id
        ORDER BY p.id DESC";
$products = $pdo->query($sql)->fetchAll();
?>

<?php if (!empty($err)): ?>
  <div class="alert alert-danger"><?= h($err) ?></div>
<?php endif; ?>

<div class="card mt-3">
  <div class="card-header"><h3 class="card-title">新增产品</h3></div>
  <div class="card-body">
    <form method="post" class="row g-3" id="form-add-product">
      <input type="hidden" name="action" value="add">

      <div class="col-md-4">
        <label class="form-label">产品名称</label>
        <input class="form-control" name="name" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">SKU</label>
        <input class="form-control" name="sku">
      </div>
      <div class="col-md-3">
        <label class="form-label">分类</label>
        <select class="form-select" name="category_id" id="add-cat" required>
          <option value="">选择分类</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">子分类</label>
        <select class="form-select" name="subcategory_id" id="add-sub">
          <option value="">选择子分类</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">单位</label>
        <input class="form-control" name="unit">
      </div>
      <div class="col-md-2">
        <label class="form-label">价格</label>
        <input class="form-control" name="price" type="number" step="0.01">
      </div>
      <div class="col-md-3">
        <label class="form-label">颜色</label>
        <input class="form-control" name="color">
      </div>
      <div class="col-md-3">
        <label class="form-label">日期</label>
        <input class="form-control" name="product_date" type="date">
      </div>
      <div class="col-md-2">
        <label class="form-label">重量</label>
        <input class="form-control" name="weight" type="number" step="0.001">
      </div>
      <div class="col-md-3">
        <label class="form-label">品牌</label>
        <input class="form-control" name="brand">
      </div>
      <div class="col-md-3">
        <label class="form-label">规格</label>
        <input class="form-control" name="spec">
      </div>
      <div class="col-md-3">
        <label class="form-label">供应商</label>
        <input class="form-control" name="supplier">
      </div>
      <div class="col-md-12">
        <label class="form-label">备注</label>
        <input class="form-control" name="note">
      </div>
      <div class="col-md-3">
        <label class="form-label">初始库存</label>
        <input class="form-control" name="init_stock" type="number" step="0.001" value="0">
      </div>
      <div class="col-md-3">
        <label class="form-label">初始预定</label>
        <input class="form-control" name="init_reserved" type="number" step="0.001" value="0">
      </div>
      <div class="col-md-2 align-self-end">
        <button class="btn btn-primary w-100" type="submit">添加</button>
      </div>
    </form>
  </div>
</div>

<script>
const subsByCat = <?= json_encode($subsByCat, JSON_UNESCAPED_UNICODE) ?>;
const selCat = document.getElementById('add-cat');
const selSub = document.getElementById('add-sub');

function rebuildSubOptions(catId) {
  selSub.innerHTML = '<option value="">选择子分类</option>';
  const list = subsByCat[catId] || [];
  list.forEach(s => {
    const o = document.createElement('option');
    o.value = s.id;
    o.textContent = s.name;
    selSub.appendChild(o);
  });
}
if (selCat) {
  selCat.addEventListener('change', function() {
    rebuildSubOptions(this.value);
  });
}
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
