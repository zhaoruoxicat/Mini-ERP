<?php
// production_order_view.php —— 排产单查看页（Tabler + 统一头部/权限/样式）
// 查看权限：boss / sales / op 可访问
// 状态修改：boss 可改全部；sales 仅可改自己订单
//
// 依赖：/inc/auth.php（登录校验、csrf_token()/csrf_validate()、ROLE_* 常量、可能包含 current_user_id）
//       /db.php（PDO 连接）
// 表结构：
// - production_orders(id, order_no, customer_name, sales_user_id, planner_user_id, status_id, scheduled_date, due_date, note, version, created_at, updated_at)
// - production_order_items(id, order_id, product_sku, product_name, spec, color, qty, unit, note, created_at)
// - production_statuses(id, name, key_name, sort_order, is_final)
// - users(id, username, role)

declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/inc/auth.php';
date_default_timezone_set('Asia/Shanghai');

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('current_user_role')) {
  function current_user_role(): string { return (string)($_SESSION['user']['role'] ?? ''); }
}
if (!function_exists('current_user_id')) {
  function current_user_id(): int { return (int)($_SESSION['user']['id'] ?? 0); }
}

// ---------- 权限：允许 boss / sales / op 查看 ----------
$role = current_user_role();
$allowViewRoles = [ROLE_BOSS, ROLE_SALES, ROLE_OP];
if (!in_array($role, $allowViewRoles, true)) {
  http_response_code(403);
  require __DIR__ . '/inc/header.php';
  echo '<div class="container-xl py-6 text-center">'
     . '<div style="font-size:28px;color:#d63939;font-weight:700;">权限不足</div>'
     . '<div class="text-secondary mt-2">您没有访问此页面的权限。</div>'
     . '</div>';
  require __DIR__ . '/inc/footer.php';
  exit;
}

// ---------- 入参 ----------
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  require __DIR__ . '/inc/header.php';
  echo '<div class="container-xl py-3"><div class="alert alert-danger">参数错误：缺少 id</div></div>';
  require __DIR__ . '/inc/footer.php';
  exit;
}

// ---------- 状态字典（供展示与可能的下拉） ----------
$statuses = $pdo->query("SELECT id, name FROM production_statuses ORDER BY sort_order ASC")
                ->fetchAll(PDO::FETCH_KEY_PAIR); // [id => name]

// ---------- 读取订单 ----------
$sql = "SELECT po.*,
               s.name AS status_name,
               u1.username AS sales_username,
               u2.username AS planner_username
        FROM production_orders po
        JOIN production_statuses s ON s.id = po.status_id
        LEFT JOIN users u1 ON u1.id = po.sales_user_id
        LEFT JOIN users u2 ON u2.id = po.planner_user_id
        WHERE po.id = ?";
$st = $pdo->prepare($sql);
$st->execute([$id]);
$order = $st->fetch(PDO::FETCH_ASSOC);

if (!$order) {
  http_response_code(404);
  require __DIR__ . '/inc/header.php';
  echo '<div class="container-xl py-3"><div class="alert alert-danger">未找到该排产单</div></div>';
  require __DIR__ . '/inc/footer.php';
  exit;
}

// ---------- 销售只能查看自己的订单 ----------
$isOwner = ((int)($order['sales_user_id'] ?? 0) === current_user_id());
if ($role === ROLE_SALES && !$isOwner) {
  http_response_code(403);
  require __DIR__ . '/inc/header.php';
  echo '<div class="container-xl py-6 text-center">'
     . '<div style="font-size:28px;color:#d63939;font-weight:700;">权限不足</div>'
     . '<div class="text-secondary mt-2">该排产单不属于你，无法查看。</div>'
     . '</div>';
  require __DIR__ . '/inc/footer.php';
  exit;
}

// ---------- 读取明细 ----------
$it = $pdo->prepare("SELECT * FROM production_order_items WHERE order_id=? ORDER BY id ASC");
$it->execute([$id]);
$items = $it->fetchAll(PDO::FETCH_ASSOC);

// ---------- 本页是否允许修改状态 ----------
// 规则：boss 可改全部；sales 仅在自己订单上可改；op 查看但不可在此页改（如需可自行放开）
$canEditStatus = ($role === ROLE_BOSS) || ($role === ROLE_SALES && $isOwner);

// ---------- 处理状态更新 POST ----------
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'update_status') {
    if (!$canEditStatus) {
      http_response_code(403);
      $err = '当前角色不允许修改状态。';
    } elseif (!csrf_validate($_POST['_csrf'] ?? '')) {
      http_response_code(400);
      $err = 'CSRF 校验失败，请刷新页面后重试。';
    } else {
      try {
        $status_id = (int)($_POST['status_id'] ?? 0);
        $version   = (int)($_POST['version'] ?? 0);
        if ($status_id <= 0 || !isset($statuses[$status_id])) {
          throw new RuntimeException('无效的状态值');
        }
        $upd = $pdo->prepare("UPDATE production_orders SET status_id=?, version=version+1 WHERE id=? AND version=?");
        $upd->execute([$status_id, $id, $version]);
        if ($upd->rowCount() === 0) {
          throw new RuntimeException('数据已被他人修改，请刷新后重试');
        }
        header('Location: production_order_view.php?id='.$id.'&ok=1');
        exit;
      } catch (Throwable $e) {
        $err = $e->getMessage();
      }
    }
  }
}

// ---------- 页面 ----------
require __DIR__ . '/inc/header.php';
?>
<div class="container-xl">

  <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success" role="alert">状态已更新。</div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-danger" role="alert"><?= h($err) ?></div>
  <?php endif; ?>

  <div class="page-header d-print-none">
    <div class="row align-items-center">
      <div class="col">
        <h2 class="page-title">排产单详情</h2>
        <div class="text-muted mt-1 d-flex align-items-center gap-2">
          <div>当前状态：</div>
          <?php if ($canEditStatus): ?>
            <!-- 独立状态更新表单：boss / sales(仅本人) 可用 -->
            <form method="post" class="d-inline-flex align-items-center gap-2" action="">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="version" value="<?= (int)$order['version'] ?>">
              <select class="form-select" name="status_id" style="min-width:220px">
                <?php
                $curSid = (int)$order['status_id'];
                foreach ($statuses as $sid => $sname):
                ?>
                  <option value="<?= (int)$sid ?>" <?= $sid==$curSid?'selected':'' ?>><?= h($sname) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-outline-primary" type="submit">更新状态</button>
            </form>
          <?php else: ?>
            <span class="badge bg-indigo-lt"><?= h($order['status_name'] ?? '未创建') ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-auto ms-auto d-print-none">
        <div class="btn-list">
          <a class="btn" href="production_order_list.php">返回列表</a>
          <a class="btn btn-outline-primary" href="production_order_edit.php?id=<?= (int)$id ?>">去编辑页</a>
        </div>
      </div>
    </div>
  </div>

  <div class="row row-cards">
    <!-- 基本信息 -->
    <div class="col-12">
      <div class="card">
        <div class="card-header"><h3 class="card-title">基本信息</h3></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-3">
              <div class="form-label">排产单号</div>
              <div class="text-reset"><?= h($order['order_no']) ?></div>
            </div>
            <div class="col-md-3">
              <div class="form-label">客户名称</div>
              <div class="text-reset"><?= h($order['customer_name'] ?? '') ?></div>
            </div>
            <div class="col-md-3">
              <div class="form-label">下单人</div>
              <div class="text-reset"><?= h($order['sales_username'] ?? '—') ?></div>
            </div>
            <div class="col-md-3">
              <div class="form-label">计划员</div>
              <div class="text-reset"><?= h($order['planner_username'] ?? '—') ?></div>
            </div>

            <div class="col-md-3">
              <div class="form-label">计划开工日期</div>
              <div class="text-reset"><?= h($order['scheduled_date'] ?: '—') ?></div>
            </div>
            <div class="col-md-3">
              <div class="form-label">计划完成日期</div>
              <div class="text-reset"><?= h($order['due_date'] ?: '—') ?></div>
            </div>
            <div class="col-md-3">
              <div class="form-label">创建时间</div>
              <div class="text-reset"><?= h($order['created_at'] ?? '—') ?></div>
            </div>
            <div class="col-md-3">
              <div class="form-label">最后更新</div>
              <div class="text-reset"><?= h($order['updated_at'] ?? '—') ?></div>
            </div>

            <div class="col-12">
              <div class="form-label">备注</div>
              <div class="text-reset"><?= nl2br(h($order['note'] ?? '')) ?: '—' ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 产品明细 -->
    <div class="col-12">
      <div class="card">
        <div class="card-header"><h3 class="card-title">产品明细</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter">
            <thead>
              <tr>
                <th style="width:12%">SKU</th>
                <th style="width:22%">名称</th>
                <th style="width:16%">规格</th>
                <th style="width:12%">颜色</th>
                <th class="text-end" style="width:10%">数量</th>
                <th style="width:10%">单位</th>
                <th>备注</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
              <tr><td colspan="7" class="text-secondary">暂无明细</td></tr>
            <?php else:
              foreach ($items as $it): ?>
              <tr>
                <td><?= h($it['product_sku'] ?? '') ?></td>
                <td><?= h($it['product_name'] ?? '') ?></td>
                <td><?= h($it['spec'] ?? '') ?></td>
                <td><?= h($it['color'] ?? '') ?></td>
                <td class="text-end"><?= h((string)($it['qty'] ?? '')) ?></td>
                <td><?= h($it['unit'] ?? 'pcs') ?></td>
                <td><?= h($it['note'] ?? '') ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- 版本信息 -->
    <div class="col-12">
      <div class="card">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div class="text-secondary">当前版本：<?= (int)$order['version'] ?></div>
          <div>
            <a class="btn btn-outline-secondary" href="production_order_list.php">返回列表</a>
            <a class="btn btn-outline-primary" href="production_order_edit.php?id=<?= (int)$id ?>">去编辑页</a>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
