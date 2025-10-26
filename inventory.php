<?php
// inventory.php
// 库存管理：老板 + 库管&排产 可访问；无权限时使用统一的“权限不足”提示样式
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/inc/auth.php';

require_login(); // 必须登录，后续在页面内做角色检查（以便输出统一提示样式）

// ---------- 角色与权限 ----------
if (!defined('ROLE_BOSS'))  define('ROLE_BOSS',  'boss');
if (!defined('ROLE_OP'))    define('ROLE_OP',    'op');
if (!defined('ROLE_SALES')) define('ROLE_SALES', 'sales');

// 从 session 读取当前角色（与前面页面一致）
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
      <div class="sub-text">仅老板或库管&排产可访问库存管理页面</div>
    </div>
  </div>
  <?php
  include __DIR__ . '/inc/footer.php';
  exit;
}

/** ------ Utils ------ */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

/** 处理动作：新增流水 / 删除产品 */
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'add_move') {
      $product_id = (int)($_POST['product_id'] ?? 0);
      $move_type  = $_POST['move_type'] ?? '';
      $qty = (float)($_POST['quantity'] ?? 0);
      $note = trim($_POST['note'] ?? '') ?: null;

      if (in_array($move_type, ['in','out','adjust'], true) && $product_id>0 && $qty!=0.0) {
        $st = $pdo->prepare("INSERT INTO inventory_moves(product_id, move_type, quantity, note) VALUES(?,?,?,?)");
        $st->execute([$product_id, $move_type, $qty, $note]);
      }
    } elseif ($action === 'delete_product') {
      $pid = (int)($_POST['product_id'] ?? 0);
      if ($pid > 0) {
        $st = $pdo->prepare("DELETE FROM products WHERE id=?");
        $st->execute([$pid]);
      }
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

/** 页面级筛选（右侧库存表用） */
$filter_cat = (int)($_GET['cat'] ?? 0);
$filter_sub = (int)($_GET['sub'] ?? 0);

/** 基础数据 */
$cats = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();

/** 子分类（用于右侧筛选的二级联动） */
$subs = [];
if ($filter_cat>0) {
  $st = $pdo->prepare("SELECT * FROM subcategories WHERE category_id=? ORDER BY sort_order ASC, id ASC");
  $st->execute([$filter_cat]);
  $subs = $st->fetchAll();
}

/** 全量产品（供左侧“新增库存流水”三级联动使用） */
$allProducts = $pdo->query(
  "SELECT p.id, p.name, p.sku, p.category_id, p.subcategory_id,
          c.name AS category_name, sc.name AS subcategory_name
   FROM products p
   JOIN categories c ON p.category_id=c.id
   LEFT JOIN subcategories sc ON p.subcategory_id=sc.id
   ORDER BY c.sort_order ASC, sc.sort_order ASC, p.name ASC"
)->fetchAll();

/** 右侧库存表要展示的产品（可带页面筛选） */
$sqlP = "SELECT p.*, c.name AS category_name, sc.name AS subcategory_name
         FROM products p
         JOIN categories c ON p.category_id=c.id
         LEFT JOIN subcategories sc ON p.subcategory_id=sc.id";
$where=[];$args=[];
if ($filter_cat>0){$where[]="p.category_id=?";$args[]=$filter_cat;}
if ($filter_sub>0){$where[]="p.subcategory_id=?";$args[]=$filter_sub;}
if ($where){$sqlP.=" WHERE ".implode(' AND ',$where);}
$sqlP.=" ORDER BY p.name ASC";
$stP=$pdo->prepare($sqlP);$stP->execute($args);
$products=$stP->fetchAll();

/** 计算可用库存（优先用视图） */
$byId = [];
foreach ($products as $p) $byId[(int)$p['id']] = ['stock'=>0.0,'reserved'=>0.0,'available'=>0.0];

$useView = false;
try {
  $useView = true;
  $ids = array_keys($byId);
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sv = $pdo->prepare("SELECT * FROM v_available_stock WHERE product_id IN ($in)");
    $sv->execute($ids);
    foreach ($sv->fetchAll() as $row) {
      $pid = (int)$row['product_id'];
      $byId[$pid]['stock']     = (float)$row['stock_qty'];
      $byId[$pid]['reserved']  = (float)$row['reserved_qty'];
      $byId[$pid]['available'] = (float)$row['available_qty'];
    }
  }
} catch (Throwable $e) {
  $useView = false;
}
/** 视图不可用时回退 */
if (!$useView && $byId) {
  $ids = array_keys($byId);
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT product_id, move_type, quantity FROM inventory_moves WHERE product_id IN ($in)");
  $st->execute($ids);
  foreach ($st->fetchAll() as $r) {
    $pid=(int)$r['product_id']; $q=(float)$r['quantity'];
    if ($r['move_type']==='in') $byId[$pid]['stock'] += $q;
    elseif ($r['move_type']==='out') $byId[$pid]['stock'] -= $q;
    else $byId[$pid]['stock'] += $q;
  }
  $st = $pdo->prepare("SELECT product_id, quantity FROM reservations WHERE status='pending' AND product_id IN ($in)");
  $st->execute($ids);
  foreach ($st->fetchAll() as $r) {
    $pid=(int)$r['product_id']; $byId[$pid]['reserved'] += (float)$r['quantity'];
  }
  foreach ($byId as $pid=>&$v) { $v['available'] = $v['stock'] - $v['reserved']; }
}
?>
<?php if (!empty($err)): ?>
  <div class="alert alert-danger"><?= h($err) ?></div>
<?php endif; ?>

<div class="row row-cards">
  <!-- 左侧：新增库存流水 -->
  <div class="col-md-5 col-lg-4">
    <div class="card">
      <div class="card-header"><h3 class="card-title">新增库存流水</h3></div>
      <div class="card-body">
        <form method="post" class="row g-2" id="form-add-move">
          <input type="hidden" name="action" value="add_move">
          <div class="col-12">
            <label class="form-label">分类</label>
            <select class="form-select" id="l-cat">
              <option value="">选择分类</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">子分类</label>
            <select class="form-select" id="l-sub" disabled>
              <option value="">选择子分类</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label">产品</label>
            <select class="form-select" name="product_id" id="l-prod" required disabled>
              <option value="">选择产品</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">类型</label>
            <select class="form-select" name="move_type" required>
              <option value="in">入库(+)</option>
              <option value="out">出库(-)</option>
              <option value="adjust">调整(+/-)</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">数量</label>
            <input class="form-control" name="quantity" type="number" step="0.001" placeholder="可负数">
          </div>
          <div class="col-12">
            <label class="form-label">备注</label>
            <input class="form-control" name="note" placeholder="可选">
          </div>
          <div class="col-md-4">
            <button class="btn btn-primary w-100">保存</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header"><h3 class="card-title">库存视图筛选（右侧）</h3></div>
      <div class="card-body">
        <form method="get" class="row g-2">
          <div class="col-6">
            <label class="form-label">分类</label>
            <select class="form-select" name="cat" onchange="this.form.submit()">
              <option value="0">全部分类</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $filter_cat===$c['id']?'selected':'' ?>>
                  <?= h($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">子分类</label>
            <select class="form-select" name="sub" onchange="this.form.submit()">
              <option value="0">全部子分类</option>
              <?php foreach ($subs as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $filter_sub===$s['id']?'selected':'' ?>>
                  <?= h($s['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label d-block">&nbsp;</label>
            <a class="btn btn-outline-secondary w-100" href="inventory.php">重置</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 右侧：库存视图 -->
  <div class="col-md-7 col-lg-8">
    <div class="card">
      <div class="card-header"><h3 class="card-title">库存视图</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter" style="table-layout:auto; width:auto; min-width:100%;">
          <thead>
            <tr>
              <th>分类 / 子分类</th>
              <th>名称</th>
              <th>SKU</th>
              <th>颜色</th>
              <th class="text-end">重量</th>
              <th>规格</th>
              <th>备注</th>
              <th class="text-end">库存</th>
              <th class="text-end">可用</th>
              <th class="text-end">预定</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($products as $p):
            $meta = $byId[(int)$p['id']] ?? ['stock'=>0,'reserved'=>0,'available'=>0];
            $fmt = fn($n)=>rtrim(rtrim(number_format((float)$n,3,'.',''), '0'),'.');
            $avail = (float)$meta['available'];
            $cls = $avail < 0 ? 'text-danger' : 'text-body-secondary';
          ?>
            <tr>
              <td><?= h($p['category_name']) ?> / <?= h($p['subcategory_name'] ?? '-') ?></td>
              <td><?= h($p['name'] ?? '') ?></td>
              <td><?= h($p['sku'] ?? '') ?></td>
              <td><?= h($p['color'] ?? '') ?></td>
              <td class="text-end"><?= $p['weight']!==null && $p['weight']!=='' ? h($fmt($p['weight'])) : '' ?></td>
              <td><?= h($p['spec'] ?? ($p['specs'] ?? '')) ?></td>
              <td style="max-width:280px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= h($p['note'] ?? ($p['remarks'] ?? '')) ?>">
                <?= h($p['note'] ?? ($p['remarks'] ?? '')) ?>
              </td>
              <td class="text-end"><?= h($fmt($meta['stock'])) ?></td>
              <td class="text-end">
                <span class="<?= $cls ?>"><?= h($fmt($avail)) ?></span>
              </td>
              <td class="text-end"><?= h($fmt($meta['reserved'])) ?></td>
              <td class="text-nowrap">
                <a href="products_edit.php?id=<?= (int)$p['id'] ?>" class="btn btn-outline-primary btn-sm">编辑</a>
                <form method="post" class="d-inline" onsubmit="return confirm('删除该产品将同时删除其库存流水与预定，确定？');">
                  <input type="hidden" name="action" value="delete_product">
                  <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-outline-danger btn-sm">删除</button>
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

<script>
const allProducts = <?= json_encode($allProducts, JSON_UNESCAPED_UNICODE) ?>;
const byCat = {}, byCatSub = {};
allProducts.forEach(p => {
  const cat = String(p.category_id || '');
  const sub = String(p.subcategory_id || '');
  if (!byCat[cat]) byCat[cat] = new Set();
  byCat[cat].add(sub);
  if (!byCatSub[cat]) byCatSub[cat] = {};
  if (!byCatSub[cat][sub]) byCatSub[cat][sub] = [];
  byCatSub[cat][sub].push(p);
});
const selCat = document.getElementById('l-cat');
const selSub = document.getElementById('l-sub');
const selProd= document.getElementById('l-prod');
function rebuildSubs(catId) {
  selSub.innerHTML = '<option value="">选择子分类</option>';
  selSub.disabled = true;
  selProd.innerHTML = '<option value="">选择产品</option>';
  selProd.disabled = true;
  if (!catId) return;
  const subSet = byCat[String(catId)];
  if (!subSet) return;
  const options = [];
  options.push(new Option('全部子分类', '0'));
  [...subSet].forEach(subId => {
    if (subId && subId !== '0') {
      const one = (byCatSub[String(catId)][subId] || [])[0];
      const subName = one ? (one.subcategory_name || '-') : ('ID '+subId);
      options.push(new Option(subName, subId));
    }
  });
  options.forEach(o => selSub.add(o));
  selSub.disabled = false;
}
function rebuildProducts(catId, subId) {
  selProd.innerHTML = '<option value="">选择产品</option>';
  selProd.disabled = true;
  if (!catId) return;
  let list = [];
  if (subId === '0' || !subId) {
    const subMap = byCatSub[String(catId)] || {};
    Object.values(subMap).forEach(arr => list = list.concat(arr));
  } else {
    list = (byCatSub[String(catId)] || {})[String(subId)] || [];
  }
  list.forEach(p => {
    const label = `[${p.category_name}/${p.subcategory_name || '-'}] ${p.name}${p.sku ? ' - '+p.sku : ''}`;
    selProd.add(new Option(label, p.id));
  });
  if (list.length) selProd.disabled = false;
}
if (selCat) selCat.addEventListener('change', function() { rebuildSubs(this.value); });
if (selSub) selSub.addEventListener('change', function() { rebuildProducts(selCat.value, this.value); });
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
