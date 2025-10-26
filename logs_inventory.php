<?php
// logs_inventory.php
// 库存操作日志（inventory_moves）：老板可见；分类/子分类/产品联动；类型中文显示
declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/db.php';
date_default_timezone_set('Asia/Shanghai');

// ========== 小工具 ==========
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function tableHasColumn(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k = $table.'|'.$col;
  if (array_key_exists($k, $cache)) return $cache[$k];
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $st->execute([$table, $col]);
  return $cache[$k] = (bool)$st->fetchColumn();
}

// 角色常量
if (!defined('ROLE_BOSS'))  define('ROLE_BOSS',  'boss');
if (!defined('ROLE_OP'))    define('ROLE_OP',    'op');
if (!defined('ROLE_SALES')) define('ROLE_SALES', 'sales');

// 读取当前用户角色
$sessionUser = $_SESSION['user'] ?? null;
$currRole = null;
if (is_array($sessionUser)) {
  $currRole = $sessionUser['role'] ?? ($_SESSION['role'] ?? null);
} elseif (is_object($sessionUser)) {
  $currRole = $sessionUser->role ?? ($_SESSION['role'] ?? null);
} else {
  $currRole = $_SESSION['role'] ?? null;
}

// 先包含头部（无论有没有权限，都展示导航）
include __DIR__ . '/inc/header.php';

// 若不是老板，显示中间大字提示并结束
if ($currRole !== ROLE_BOSS) {
  ?>
  <style>
    .center-wrap { min-height: 60vh; display:flex; align-items:center; justify-content:center; }
    .big-deny   { font-size: 42px; font-weight: 800; color:#c92a2a; letter-spacing: .06em; }
    .sub-text   { color:#6b7280; margin-top:10px; }
  </style>
  <div class="center-wrap">
    <div class="text-center">
      <div class="big-deny">权限不足</div>
      <div class="sub-text">仅老板（管理员）可查看库存操作日志</div>
    </div>
  </div>
  <?php
  include __DIR__ . '/inc/footer.php';
  exit;
}

// ========== 列探测（用于产品下拉展示更多信息） ==========
$hasColor = tableHasColumn($pdo, 'products', 'color');
$hasSpec  = tableHasColumn($pdo, 'products', 'spec');
$hasSpecs = tableHasColumn($pdo, 'products', 'specs');

// ========== 筛选参数 ==========
$cnTypes = [
  'in'        => '入库',
  'out'       => '出库',
  'reserve'   => '预定',
  'unreserve' => '释放',
  'adjust'    => '调整',
];
$validDbTypes = array_keys($cnTypes);

$type = (string)($_GET['type'] ?? '');
$pid  = (int)($_GET['product_id'] ?? 0);
$cid  = (int)($_GET['category_id'] ?? 0);
$scid = (int)($_GET['subcategory_id'] ?? 0);
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

// ========== 分类 & 子分类 ==========
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();
$subcats = [];
if ($cid > 0) {
  $st = $pdo->prepare("SELECT id, name FROM subcategories WHERE category_id=? ORDER BY sort_order ASC, id ASC");
  $st->execute([$cid]);
  $subcats = $st->fetchAll();
}

// ========== 产品下拉（随分类/子分类联动） ==========
// 1) 先按分类/子分类加载产品列表
$pWhere = [];
$pArgs  = [];
$pSelect = "SELECT p.id, p.name, p.category_id, p.subcategory_id, c.name AS c_name, sc.name AS sc_name"
         . ($hasColor ? ", p.color" : "")
         . ($hasSpec  ? ", p.spec"  : "")
         . ($hasSpecs ? ", p.specs" : "")
         . " FROM products p
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN subcategories sc ON sc.id = p.subcategory_id";

if ($cid > 0)  { $pWhere[] = "p.category_id = ?";   $pArgs[] = $cid; }
if ($scid > 0) { $pWhere[] = "p.subcategory_id = ?";$pArgs[] = $scid; }

if ($pWhere) $pSelect .= " WHERE ".implode(" AND ", $pWhere);
$pSelect .= " ORDER BY p.name ASC LIMIT 800";

$products = $pdo->prepare($pSelect);
$products->execute($pArgs);
$products = $products->fetchAll();

// 2) 若已选中的产品 $pid 不在当前联动范围内，则追加它，避免丢失选择
if ($pid > 0) {
  $found = false;
  foreach ($products as $pp) { if ((int)$pp['id'] === $pid) { $found = true; break; } }
  if (!$found) {
    $oneSql = "SELECT p.id, p.name, p.category_id, p.subcategory_id, c.name AS c_name, sc.name AS sc_name"
            . ($hasColor ? ", p.color" : "")
            . ($hasSpec  ? ", p.spec"  : "")
            . ($hasSpecs ? ", p.specs" : "")
            . " FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN subcategories sc ON sc.id = p.subcategory_id
               WHERE p.id = ? LIMIT 1";
    $stOne = $pdo->prepare($oneSql);
    $stOne->execute([$pid]);
    if ($extra = $stOne->fetch()) {
      $products[] = $extra; // 末尾追加
    }
  }
}

// ========== WHERE 条件（日志列表） ==========
$where = []; $args = [];

if ($type !== '' && in_array($type, $validDbTypes, true)) {
  $where[] = "m.move_type = ?";        $args[] = $type;
}
if ($pid > 0) {
  $where[] = "m.product_id = ?";       $args[] = $pid;
}
if ($cid > 0) {
  $where[] = "p.category_id = ?";      $args[] = $cid;
}
if ($scid > 0) {
  $where[] = "p.subcategory_id = ?";   $args[] = $scid;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
  $where[] = "m.created_at >= ?";      $args[] = $from . ' 00:00:00';
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
  $where[] = "m.created_at <= ?";      $args[] = $to . ' 23:59:59';
}

// ========== 分页 ==========
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// 统计
$sqlCount = "SELECT COUNT(*)
             FROM inventory_moves m
             LEFT JOIN products p ON p.id = m.product_id";
if ($where) $sqlCount .= " WHERE " . implode(" AND ", $where);
$stCnt = $pdo->prepare($sqlCount);
$stCnt->execute($args);
$total = (int)$stCnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

// 列表
$sql = "SELECT m.*, p.name AS product_name, p.category_id, p.subcategory_id,
               c.name AS category_name, sc.name AS subcategory_name
        FROM inventory_moves m
        LEFT JOIN products p ON p.id = m.product_id
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN subcategories sc ON sc.id = p.subcategory_id";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY m.created_at DESC, m.id DESC
          LIMIT {$perPage} OFFSET {$offset}";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();
?>

<style>
  .cell-note { max-width: 520px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .opt-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
</style>

<div class="row row-cards">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0">库存操作日志</h3>
        <span class="text-muted">共 <?= (int)$total ?> 条</span>
      </div>

      <div class="card-body">
        <form class="row g-2" method="get" id="filterForm">
          <!-- 分类 -->
          <div class="col-12 col-md-2">
            <label class="form-label">分类</label>
            <select name="category_id" class="form-select" onchange="document.getElementById('filterForm').submit()">
              <option value="0">全部分类</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $cid===(int)$c['id']?'selected':'' ?>>
                  <?= h($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 子分类 -->
          <div class="col-12 col-md-2">
            <label class="form-label">子分类</label>
            <select name="subcategory_id" class="form-select" onchange="document.getElementById('filterForm').submit()">
              <option value="0">全部子分类</option>
              <?php foreach ($subcats as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $scid===(int)$s['id']?'selected':'' ?>>
                  <?= h($s['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 产品（随分类联动，显示更多信息） -->
          <div class="col-12 col-md-4">
            <label class="form-label">产品</label>
            <select name="product_id" class="form-select">
              <option value="0">全部产品</option>
              <?php foreach ($products as $p):
                $parts = [];
                $parts[] = $p['name'] ?? '';
                $tag = trim(($p['c_name'] ?? '') . '/' . ($p['sc_name'] ?? ''), '/');
                if ($tag !== '') $parts[] = '[' . $tag . ']';
                if ($hasColor && !empty($p['color'])) $parts[] = (string)$p['color'];
                $specText = '';
                if ($hasSpec  && !empty($p['spec']))  $specText = (string)$p['spec'];
                if ($hasSpecs && !empty($p['specs'])) $specText = $specText ? ($specText.' | '.$p['specs']) : (string)$p['specs'];
                if ($specText !== '') $parts[] = $specText;
                $parts[] = '#' . (int)$p['id'];
                $label = implode(' | ', array_filter($parts, fn($x)=>$x!=='' && $x!==null));
              ?>
                <option class="opt-mono" value="<?= (int)$p['id'] ?>" <?= $pid===(int)$p['id']?'selected':'' ?>>
                  <?= h($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 类型（中文显示，值为英文） -->
          <div class="col-6 col-md-2">
            <label class="form-label">类型</label>
            <select name="type" class="form-select">
              <option value="">全部类型</option>
              <?php foreach ($cnTypes as $k=>$v): ?>
                <option value="<?= h($k) ?>" <?= $type===$k?'selected':'' ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 日期 -->
          <div class="col-6 col-md-2">
            <label class="form-label">起始日期</label>
            <input type="date" name="from" value="<?= h($from) ?>" class="form-control">
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label">截止日期</label>
            <input type="date" name="to" value="<?= h($to) ?>" class="form-control">
          </div>

          <!-- 操作 -->
          <div class="col-6 col-md-2">
            <label class="form-label d-block">&nbsp;</label>
            <button class="btn btn-primary w-100">筛选</button>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label d-block">&nbsp;</label>
            <a class="btn btn-outline-secondary w-100" href="logs_inventory.php">重置</a>
          </div>
        </form>
      </div>

      <div class="table-responsive">
        <table class="table table-vcenter table-striped">
          <thead>
            <tr>
              <th style="width:80px;">ID</th>
              <th style="width:120px;">类型</th>
              <th>产品</th>
              <th>分类/子分类</th>
              <th class="text-end" style="width:140px;">数量</th>
              <th>备注</th>
              <th style="width:180px;">时间</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="7" class="text-secondary">没有匹配的记录</td></tr>
            <?php else: foreach ($rows as $r): ?>
              <?php
                $typeCn = $cnTypes[$r['move_type']] ?? $r['move_type'];
                $cls = 'badge bg-secondary';
                switch ($r['move_type']) {
                  case 'in':         $cls = 'badge ';  break;
                  case 'out':        $cls = 'badge ';    break;
                  case 'reserve':    $cls = 'badge '; break;
                  case 'unreserve':  $cls = 'badge ';   break;
                  case 'adjust':     $cls = 'badge ';   break;
                }
              ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><span class="<?= h($cls) ?>"><?= h($typeCn) ?></span></td>
                <td><?= h($r['product_name'] ?? ('#'.$r['product_id'])) ?></td>
                <td><?= h(($r['category_name'] ?? '').' / '.($r['subcategory_name'] ?? '')) ?></td>
                <td class="text-end"><?= h(rtrim(rtrim(number_format((float)$r['quantity'], 3, '.', ''), '0'), '.')) ?></td>
                <td class="cell-note" title="<?= h($r['note'] ?? '') ?>"><?= h($r['note'] ?? '') ?></td>
                <td><?= h($r['created_at']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- 分页 -->
      <?php if ($pages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
          <div>第 <?= (int)$page ?> / <?= (int)$pages ?> 页</div>
          <div class="btn-list">
            <?php
              $base = $_GET;
              $mk = function(int $p) use ($base){ $base['page']=$p; return 'logs_inventory.php?'.http_build_query($base); };
            ?>
            <a class="btn btn-outline-secondary <?= $page<=1?'disabled':'' ?>" href="<?= $page<=1?'#':h($mk($page-1)) ?>">上一页</a>
            <a class="btn btn-outline-secondary <?= $page>=$pages?'disabled':'' ?>" href="<?= $page>=$pages?'#':h($mk($page+1)) ?>">下一页</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
