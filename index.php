<?php
// index.php — 首页：库存视图（分类/子分类、名称、颜色、规格、备注筛选）
declare(strict_types=1);
require __DIR__ . '/db.php';

// 头部（会做登录校验，并在导航右上角显示当前登录用户）
require __DIR__ . '/inc/header.php';

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('numfmt')) {
  function numfmt($n){ return rtrim(rtrim(number_format((float)$n,3,'.',''), '0'),'.'); }
}

// 判断表字段是否存在（以当前数据库为准）
function tableHasColumn(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = $table.'|'.$col;
  if (array_key_exists($key, $cache)) return $cache[$key];
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $st->execute([$table, $col]);
  return $cache[$key] = (bool)$st->fetchColumn();
}

/** 筛选参数 */
$cat     = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$sub     = isset($_GET['sub']) ? (int)$_GET['sub'] : 0;

$q_name  = trim($_GET['name']  ?? '');
$q_color = trim($_GET['color'] ?? '');
$q_spec  = trim($_GET['spec']  ?? '');
$q_note  = trim($_GET['note']  ?? '');

/** 分类、子分类 */
$cats = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$subs = [];
if ($cat > 0) {
  $st = $pdo->prepare("SELECT * FROM subcategories WHERE category_id=? ORDER BY sort_order ASC, id ASC");
  $st->execute([$cat]);
  $subs = $st->fetchAll(PDO::FETCH_ASSOC);
}

/** 探测 products 表可用字段（避免引用不存在列导致 1054 错误） */
$hasName    = tableHasColumn($pdo, 'products', 'name');
$hasColor   = tableHasColumn($pdo, 'products', 'color');
$hasSpec    = tableHasColumn($pdo, 'products', 'spec');
$hasSpecs   = tableHasColumn($pdo, 'products', 'specs');
$hasNote    = tableHasColumn($pdo, 'products', 'note');
$hasRemarks = tableHasColumn($pdo, 'products', 'remarks');

/** 产品查询（只对存在的列生成 WHERE 条件）
 * 注意：categories 用 INNER JOIN；如果你担心孤儿记录被过滤，可把第一个 JOIN 改成 LEFT JOIN
 */
$sql = "SELECT p.*, c.name AS category_name, sc.name AS subcategory_name
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN subcategories sc ON p.subcategory_id = sc.id";
$where = [];
$args  = [];

if ($cat > 0) { $where[] = "p.category_id = ?";    $args[] = $cat; }
if ($sub > 0) { $where[] = "p.subcategory_id = ?"; $args[] = $sub; }

if ($q_name !== '' && $hasName) {
  $where[] = "p.name LIKE ?";
  $args[]  = "%{$q_name}%";
}
if ($q_color !== '' && $hasColor) {
  $where[] = "p.color LIKE ?";
  $args[]  = "%{$q_color}%";
}
if ($q_spec !== '') {
  $specConds = [];
  if ($hasSpec)  { $specConds[] = "p.spec LIKE ?";  $args[] = "%{$q_spec}%"; }
  if ($hasSpecs) { $specConds[] = "p.specs LIKE ?"; $args[] = "%{$q_spec}%"; }
  if ($specConds) {
    $where[] = '(' . implode(' OR ', $specConds) . ')';
  }
}
if ($q_note !== '') {
  $noteConds = [];
  if ($hasNote)    { $noteConds[] = "p.note LIKE ?";    $args[] = "%{$q_note}%"; }
  if ($hasRemarks) { $noteConds[] = "p.remarks LIKE ?"; $args[] = "%{$q_note}%"; }
  if ($noteConds) {
    $where[] = '(' . implode(' OR ', $noteConds) . ')';
  }
}

if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY c.sort_order ASC, sc.sort_order ASC, p.name ASC";

$st = $pdo->prepare($sql);
$st->execute($args);
$products = $st->fetchAll(PDO::FETCH_ASSOC);

/** 计算库存/预定/可用（优先视图 v_available_stock；失败则回退到手动聚合） */
$byId = [];
foreach ($products as $p) $byId[(int)$p['id']] = ['stock'=>0.0,'reserved'=>0.0,'available'=>0.0];

$useView = false;
try {
  $ids = array_keys($byId);
  if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sv = $pdo->prepare("SELECT product_id, stock_qty, reserved_qty, available_qty FROM v_available_stock WHERE product_id IN ($placeholders)");
    $sv->execute($ids);
    foreach ($sv->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $pid = (int)$row['product_id'];
      $byId[$pid]['stock']     = (float)$row['stock_qty'];
      $byId[$pid]['reserved']  = (float)$row['reserved_qty'];
      $byId[$pid]['available'] = (float)$row['available_qty'];
    }
  }
  $useView = true;
} catch (Throwable $e) {
  $useView = false;
}

// 视图不可用时回退：inventory_moves + reservations
if (!$useView && $byId) {
  $ids = array_keys($byId);
  $in  = implode(',', array_fill(0, count($ids), '?'));

  // 汇总库存
  $st = $pdo->prepare("SELECT product_id, move_type, quantity FROM inventory_moves WHERE product_id IN ($in)");
  $st->execute($ids);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $pid = (int)$r['product_id'];
    $q   = (float)$r['quantity'];
    if ($r['move_type'] === 'in')      $byId[$pid]['stock'] += $q;
    elseif ($r['move_type'] === 'out') $byId[$pid]['stock'] -= $q;
    else                               $byId[$pid]['stock'] += $q; // adjust
  }

  // 汇总预定 (仅 pending)
  $st = $pdo->prepare("SELECT product_id, quantity FROM reservations WHERE status='pending' AND product_id IN ($in)");
  $st->execute($ids);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $pid = (int)$r['product_id'];
    $byId[$pid]['reserved'] += (float)$r['quantity'];
  }

  // 可用 = 库存 - 预定
  foreach ($byId as $pid => &$v) {
    $v['available'] = $v['stock'] - $v['reserved'];
  }
  unset($v);
}
?>
<style>
  .table-fit { table-layout:auto; width:auto; min-width:100%; }
  .table thead th {
    position: sticky; top:0; z-index:2;
    background: var(--tblr-bg-surface, var(--tblr-body-bg));
  }
  .table-lg td, .table-lg th { padding-top:.8rem; padding-bottom:.8rem; }
  .cell-note { max-width:420px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
</style>

<div class="row row-cards">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><h3 class="card-title">库存视图</h3></div>

      <!-- 筛选 -->
      <div class="card-body">
        <form method="get" class="row g-2">
          <div class="col-md-2">
            <label class="form-label">分类</label>
            <select class="form-select" name="cat" onchange="this.form.submit()">
              <option value="0">全部</option>
              <?php foreach ($cats as $c): $cid=(int)$c['id']; ?>
                <option value="<?= $cid ?>" <?= ($cat===$cid)?'selected':'' ?>><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">子分类</label>
            <select class="form-select" name="sub" onchange="this.form.submit()">
              <option value="0">全部</option>
              <?php foreach ($subs as $s): $sid=(int)$s['id']; ?>
                <option value="<?= $sid ?>" <?= ($sub===$sid)?'selected':'' ?>><?= h($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">名称</label>
            <input class="form-control" name="name" value="<?= h($q_name) ?>" placeholder="包含..." <?= $hasName?'':'disabled' ?>>
          </div>
          <div class="col-md-2">
            <label class="form-label">颜色</label>
            <input class="form-control" name="color" value="<?= h($q_color) ?>" placeholder="包含..." <?= $hasColor?'':'disabled' ?>>
          </div>
          <div class="col-md-2">
            <label class="form-label">规格</label>
            <input class="form-control" name="spec" value="<?= h($q_spec) ?>" placeholder="包含...">
            <?php if (!$hasSpec && !$hasSpecs): ?>
              <div class="form-hint text-danger">当前数据表无规格字段</div>
            <?php endif; ?>
          </div>
          <div class="col-md-2">
            <label class="form-label">备注</label>
            <input class="form-control" name="note" value="<?= h($q_note) ?>" placeholder="包含...">
            <?php if (!$hasNote && !$hasRemarks): ?>
              <div class="form-hint text-danger">当前数据表无备注字段</div>
            <?php endif; ?>
          </div>
          <div class="col-md-2">
            <label class="form-label d-block">&nbsp;</label>
            <button class="btn btn-primary w-100">筛选</button>
          </div>
          <div class="col-md-2">
            <label class="form-label d-block">&nbsp;</label>
            <a class="btn btn-outline-secondary w-100" href="index.php">重置</a>
          </div>
        </form>
      </div>

      <!-- 表格 -->
      <div class="table-responsive">
        <table class="table table-vcenter table-striped table-lg table-fit">
          <thead>
            <tr>
              <th>分类 / 子分类</th>
              <th>名称</th>
              <th>颜色</th>
              <th>规格</th>
              <th>备注</th>
              <th class="text-end">库存</th>
              <th class="text-end">可用</th>
              <th class="text-end">预定</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($products): ?>
              <?php foreach ($products as $p):
                $pid = (int)$p['id'];
                $m = $byId[$pid] ?? ['stock'=>0,'reserved'=>0,'available'=>0];
                $avail = (float)$m['available'];
                $cls = $avail < 0 ? 'text-danger' : ($avail == 0 ? 'text-warning' : 'text-body-secondary');
                $noteText = (($hasNote ? ($p['note'] ?? '') : '') ?: ($hasRemarks ? ($p['remarks'] ?? '') : ''));
              ?>
              <tr>
                <td><?= h($p['category_name']) ?> / <?= h($p['subcategory_name'] ?? '-') ?></td>
                <td><?= h($p['name']  ?? '') ?></td>
                <td><?= h($p['color'] ?? '') ?></td>
                <td><?= h($p['spec']  ?? ($p['specs'] ?? '')) ?></td>
                <td class="cell-note" title="<?= h($noteText) ?>"><?= h($noteText) ?></td>
                <td class="text-end"><?= h(numfmt($m['stock'])) ?></td>
                <td class="text-end"><span class="<?= $cls ?>"><?= h(numfmt($avail)) ?></span></td>
                <td class="text-end"><?= h(numfmt($m['reserved'])) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="8" class="text-secondary">没有匹配的记录。</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
