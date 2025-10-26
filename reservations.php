<?php
// reservations.php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/inc/auth.php';

require_login(); // 必须登录，后续在页面内做角色检查（以便输出统一的权限提示样式）

/* ---------- 角色与权限（页面内控制 + 统一提示样式） ---------- */
if (!defined('ROLE_BOSS'))  define('ROLE_BOSS',  'boss');
if (!defined('ROLE_OP'))    define('ROLE_OP',    'op');
if (!defined('ROLE_SALES')) define('ROLE_SALES', 'sales');

$allowedRoles = [ROLE_BOSS, ROLE_OP];

// 从 session 读取当前角色（与 logs_inventory.php 一致的判定方式）
$sessionUser = $_SESSION['user'] ?? null;
$currRole = null;
if (is_array($sessionUser)) {
  $currRole = $sessionUser['role'] ?? ($_SESSION['role'] ?? null);
} elseif (is_object($sessionUser)) {
  $currRole = $sessionUser->role ?? ($_SESSION['role'] ?? null);
} else {
  $currRole = $_SESSION['role'] ?? null;
}
$hasAccess = in_array($currRole, $allowedRoles, true);

// 统一头部（无论是否有权限，先展示导航）
include __DIR__ . '/inc/header.php';

// 无权限：输出统一排版的提示并结束
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
      <div class="sub-text">仅老板或库管&排产可访问预定管理页面</div>
    </div>
  </div>
  <?php
  include __DIR__ . '/inc/footer.php';
  exit;
}

/* ---------- Utils ---------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

/* ---------- 提交动作 ---------- */
$err = null;
$action = $_POST['action'] ?? null;

try {
  if ($action === 'add') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $quantity   = (float)($_POST['quantity'] ?? 0);
    $customer   = trim($_POST['customer'] ?? '');
    $expected   = $_POST['expected_date'] ?? null;
    $note       = trim($_POST['note'] ?? '') ?: null;

    if ($product_id > 0 && $quantity > 0) {
      $st = $pdo->prepare("INSERT INTO reservations(product_id, quantity, customer, expected_date, status, note) VALUES(?,?,?,?, 'pending', ?)");
      $st->execute([$product_id, $quantity, $customer ?: null, $expected ?: null, $note]);
    }
  } elseif ($action === 'update_status') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if ($id>0 && in_array($status, ['pending','fulfilled','cancelled'], true)) {
      $st = $pdo->prepare("UPDATE reservations SET status=? WHERE id=?");
      $st->execute([$status, $id]);
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

/* ---------- 基础数据（全量：级联下拉在前端联动过滤） ---------- */
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();
$subcategories = $pdo->query("SELECT id, name, category_id FROM subcategories ORDER BY sort_order ASC, id ASC")->fetchAll();
$products = $pdo->query(
  "SELECT p.id, p.name, p.sku, p.category_id, p.subcategory_id,
          c.name AS category_name, sc.name AS subcategory_name
   FROM products p
   JOIN categories c ON p.category_id=c.id
   LEFT JOIN subcategories sc ON p.subcategory_id=sc.id
   ORDER BY p.name ASC"
)->fetchAll();

/* ---------- 可用库存 ---------- */
$mapStock = [];
try {
  $ids = array_column($products, 'id');
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sv = $pdo->prepare("SELECT product_id, available_qty FROM v_available_stock WHERE product_id IN ($in)");
    $sv->execute($ids);
    foreach ($sv->fetchAll() as $row) {
      $mapStock[(int)$row['product_id']] = (float)$row['available_qty'];
    }
  }
} catch (Throwable $e) {}

/* ---------- 预定列表（右侧表） ---------- */
$list = $pdo->query(
  "SELECT r.*, p.name AS product_name, p.sku, c.name AS category_name, sc.name AS subcategory_name
   FROM reservations r
   JOIN products p ON r.product_id=p.id
   JOIN categories c ON p.category_id=c.id
   LEFT JOIN subcategories sc ON p.subcategory_id=sc.id
   ORDER BY r.status='pending' DESC, r.expected_date ASC, r.id DESC"
)->fetchAll();
?>
<?php if (!empty($err)): ?>
  <div class="alert alert-danger"><?= h($err) ?></div>
<?php endif; ?>

<div class="row row-cards">
  <!-- 左：新建预定（级联下拉，无“应用筛选”按钮） -->
  <div class="col-md-5">
    <div class="card">
      <div class="card-header"><h3 class="card-title">新建预定</h3></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <input type="hidden" name="action" value="add">

          <!-- 级联步骤1：分类 -->
          <div class="col-12">
            <label class="form-label">分类</label>
            <select class="form-select" id="catSelect" required>
              <option value="">请选择分类</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 级联步骤2：子分类（选择分类后才可用） -->
          <div class="col-12">
            <label class="form-label">子分类</label>
            <select class="form-select" id="subSelect" disabled required>
              <option value="">先选择分类</option>
              <?php foreach ($subcategories as $sc): ?>
                <option value="<?= (int)$sc['id'] ?>" data-cat="<?= (int)$sc['category_id'] ?>">
                  <?= h($sc['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div id="subHint" class="form-hint d-none">该分类暂无子分类，请先到“子分类管理”中添加。</div>
          </div>

          <!-- 级联步骤3：产品（选择子分类后才可用） -->
          <div class="col-12">
            <label class="form-label">产品</label>
            <select class="form-select" name="product_id" id="productSelect" disabled required>
              <option value="">先选择子分类</option>
              <?php foreach ($products as $p):
                $av = $mapStock[(int)$p['id']] ?? null; ?>
                <option value="<?= (int)$p['id'] ?>"
                        data-cat="<?= (int)$p['category_id'] ?>"
                        data-sub="<?= (int)($p['subcategory_id'] ?? 0) ?>">
                  <?= h($p['name']) ?><?= $p['sku']?(' - '.h($p['sku'])):'' ?>
                  <?= $av!==null ? ('（可用：'.rtrim(rtrim(number_format($av,3,'.',''), '0'),'.').'）') : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">数量</label>
            <input class="form-control" name="quantity" type="number" step="0.001" placeholder="数量" required>
          </div>
          <div class="col-md-8">
            <label class="form-label">客户名称（可选）</label>
            <input class="form-control" name="customer" placeholder="客户名称（可选）">
          </div>
          <div class="col-md-6">
            <label class="form-label">预计交付日期</label>
            <input class="form-control" name="expected_date" type="date">
          </div>
          <div class="col-12">
            <label class="form-label">备注（可选）</label>
            <input class="form-control" name="note" placeholder="备注（可选）">
          </div>
          <div class="col-md-4">
            <button class="btn btn-primary w-100">保存</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 右：预定列表 -->
  <div class="col-md-7">
    <div class="card">
      <div class="card-header"><h3 class="card-title">预定列表</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter">
          <thead>
            <tr>
              <th>ID</th>
              <th>产品</th>
              <th>客户</th>
              <th class="text-end">数量</th>
              <th>预计交付</th>
              <th>备注</th>
              <th>状态</th>
              <th class="w-1">操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($list as $r): ?>
            <tr>
              <td class="text-muted"><?= (int)$r['id'] ?></td>
              <td class="small">
                <div><?= h($r['product_name']) ?> <span class="text-muted"><?= h($r['sku'] ?? '') ?></span></div>
                <div class="text-muted small"><?= h($r['category_name']) ?> / <?= h($r['subcategory_name'] ?? '-') ?></div>
              </td>
              <td><?= h($r['customer']) ?></td>
              <td class="text-end"><?= rtrim(rtrim(number_format((float)$r['quantity'],3,'.',''), '0'),'.') ?></td>
              <td><?= h($r['expected_date'] ?? '-') ?></td>
              <td style="max-width:220px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= h($r['note'] ?? '') ?>">
                <?= h($r['note'] ?? '') ?>
              </td>
              <td>
                <?= $r['status']==='pending' ? '待处理' : ($r['status']==='fulfilled' ? '已完成' : '已取消') ?>
              </td>
              <td class="text-nowrap">
                <form method="post" class="d-inline" onsubmit="return confirm('确认将此预定标记为完成？');">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="status" value="fulfilled">
                  <button class="btn btn-outline-success btn-sm" <?= $r['status']!=='pending'?'disabled':'' ?>>标记完成</button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('确认取消此预定？');">
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="status" value="cancelled">
                  <button class="btn btn-outline-danger btn-sm" <?= $r['status']!=='pending'?'disabled':'' ?>>取消</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- 级联下拉联动脚本：必须按顺序选择：分类 → 子分类 → 产品 -->
<script>
(function(){
  const catSelect = document.getElementById('catSelect');
  const subSelect = document.getElementById('subSelect');
  const productSelect = document.getElementById('productSelect');
  const subHint = document.getElementById('subHint');

  function resetSub(){
    subSelect.disabled = true;
    subSelect.value = '';
    for (let i=0; i<subSelect.options.length; i++){
      const opt = subSelect.options[i];
      if (i === 0) { opt.hidden = false; continue; }
      opt.hidden = true;
      opt.selected = false;
    }
    subHint?.classList.add('d-none');
  }

  function resetProduct(placeholderText = '先选择子分类'){
    productSelect.disabled = true;
    productSelect.value = '';
    if (productSelect.options.length > 0) {
      productSelect.options[0].textContent = placeholderText;
    }
    for (let i=1; i<productSelect.options.length; i++){
      const opt = productSelect.options[i];
      opt.hidden = true;
      opt.selected = false;
    }
  }

  function onCatChange(){
    const cat = parseInt(catSelect.value || '0', 10);
    resetSub();
    resetProduct('先选择子分类');

    if (!cat) return;

    let hasSub = false;
    for (let i=1; i<subSelect.options.length; i++){
      const opt = subSelect.options[i];
      const dcat = parseInt(opt.getAttribute('data-cat') || '0', 10);
      if (dcat === cat) {
        opt.hidden = false;
        hasSub = true;
      }
    }
    if (hasSub) {
      subSelect.disabled = false;
    } else {
      subHint?.classList.remove('d-none');
    }
  }

  function onSubChange(){
    const cat = parseInt(catSelect.value || '0', 10);
    const sub = parseInt(subSelect.value || '0', 10);
    resetProduct();

    if (!cat || !sub) return;

    let hasProduct = false;
    for (let i=1; i<productSelect.options.length; i++){
      const opt = productSelect.options[i];
      const dcat = parseInt(opt.getAttribute('data-cat') || '0', 10);
      const dsub = parseInt(opt.getAttribute('data-sub') || '0', 10);
      const show = (dcat === cat) && (dsub === sub);
      opt.hidden = !show;
      if (show) hasProduct = true;
    }
    productSelect.disabled = !hasProduct;
    if (!hasProduct && productSelect.options.length>0) {
      productSelect.options[0].textContent = '该子分类下暂无产品';
    }
  }

  catSelect.addEventListener('change', onCatChange);
  subSelect.addEventListener('change', onSubChange);

  resetSub();
  resetProduct();
})();
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
