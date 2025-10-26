<?php
// production_order_edit.php —— 排产单新增/编辑页（Tabler 版 + 统一头部/权限/样式）
// 权限规则：boss/op(planner) 可修改任何订单；sales 仅能修改自己订单；其他角色禁止访问

declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/inc/auth.php';   // 登录校验、csrf_token()/csrf_validate() 等

/* ===== 强制北京时间（PHP + MySQL 会话）===== */
@ini_set('date.timezone', 'Asia/Shanghai');     // php.ini 兜底
date_default_timezone_set('Asia/Shanghai');     // PHP 进程时区 -> 北京时间
try {
  // 设置当前数据库连接的会话时区（影响 NOW()/CURRENT_TIMESTAMP/自动时间戳）
  $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $e) {
  // 如果无权限或不支持，忽略即可；PHP端仍已是北京时间
}
/* ======================================== */

if (!function_exists('current_user_id')) {
  function current_user_id(): int { return (int)($_SESSION['user']['id'] ?? 0); }
}
if (!function_exists('current_user_role')) {
  function current_user_role(): string { return (string)($_SESSION['user']['role'] ?? ''); }
}
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// ---- 访问白名单：sales / boss / op（兼容历史 'planner'）----
$roleRaw = current_user_role();
$role    = ($roleRaw === 'planner') ? 'op' : $roleRaw;
$allowRoles = ['sales','boss','op','planner'];
if (!in_array($roleRaw, $allowRoles, true)) {
  http_response_code(403);
  require __DIR__ . '/inc/header.php';
  echo '<div class="container-xl py-6 text-center">'
     . '<div style="font-size:28px;color:#d63939;font-weight:700;">权限不足</div>'
     . '<div class="text-secondary mt-2">您没有访问此页面的权限。</div>'
     . '</div>';
  require __DIR__ . '/inc/footer.php';
  exit;
}

$id     = (int)($_GET['id'] ?? 0);
$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

// ---- 状态选项（供下拉/校验）----
$statuses = $pdo->query("SELECT id, name FROM production_statuses ORDER BY sort_order ASC")
                ->fetchAll(PDO::FETCH_KEY_PAIR); // [id => name]

// ---- 读取订单/明细（编辑态）----
$order = null; $items = [];
if ($id > 0) {
  $st = $pdo->prepare(
    "SELECT po.*, s.name AS status_name, s.sort_order
       FROM production_orders po
       JOIN production_statuses s ON s.id = po.status_id
      WHERE po.id = ?"
  );
  $st->execute([$id]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
  if (!$order) {
    http_response_code(404);
    require __DIR__ . '/inc/header.php';
    echo '<div class="container-xl"><div class="alert alert-danger">未找到该排产单</div></div>';
    require __DIR__ . '/inc/footer.php';
    exit;
  }

  /* ===== 新增：销售越权拦截（不属于当前销售用户）===== */
  if ($role === 'sales' && (int)($order['sales_user_id'] ?? 0) !== current_user_id()) {
    http_response_code(403);
    require __DIR__ . '/inc/header.php';
    echo '<div class="container-xl py-6 text-center">'
       . '<div style="font-size:28px;color:#d63939;font-weight:700;">权限不足</div>'
       . '<div class="text-secondary mt-2">该排产单不属于你，无法编辑。</div>'
       . '</div>';
    require __DIR__ . '/inc/footer.php';
    exit;
  }
  /* ====================================================== */

  $it = $pdo->prepare("SELECT * FROM production_order_items WHERE order_id=? ORDER BY id ASC");
  $it->execute([$id]);
  $items = $it->fetchAll(PDO::FETCH_ASSOC);
}

// ---- 计算编辑/状态权限 ----
$isNew    = ($id === 0);
$isOwner  = $isNew ? true : ((int)($order['sales_user_id'] ?? 0) === current_user_id());

// 老板、op(planner)：可编辑任何；销售：仅新建或本人单可编辑
$canEdit         = ($role === 'boss' || $role === 'op') || ($role === 'sales' && $isOwner);
$canEditStatus   = $canEdit;          // 状态编辑与编辑权限一致
$canSave         = $canEdit;
$readOnly        = !$canEdit;

$err = '';

// ---- 处理提交 ----
if ($isPost) {
  $action = $_POST['action'] ?? '';

  // CSRF
  if (!csrf_validate($_POST['_csrf'] ?? '')) {
    http_response_code(400);
    require __DIR__ . '/inc/header.php';
    echo '<div class="container-xl"><div class="alert alert-danger">CSRF 校验失败，请刷新页面后重试。</div></div>';
    require __DIR__ . '/inc/footer.php';
    exit;
  }

  // 独立更新状态
  if ($action === 'update_status') {
    if (!$canEditStatus || $id <= 0) {
      http_response_code(403);
      require __DIR__ . '/inc/header.php';
      echo '<div class="container-xl"><div class="alert alert-danger">当前角色不允许修改状态。</div></div>';
      require __DIR__ . '/inc/footer.php';
      exit;
    }
    try {
      $status_id = (int)($_POST['status_id'] ?? 0);
      $version   = (int)($_POST['version'] ?? 1);
      if ($status_id <= 0 || !isset($statuses[$status_id])) {
        throw new RuntimeException('无效的状态值');
      }
      $st = $pdo->prepare("UPDATE production_orders SET status_id=?, version=version+1 WHERE id=? AND version=?");
      $st->execute([$status_id, $id, $version]);
      if ($st->rowCount() === 0) {
        throw new RuntimeException('数据已被他人修改，请刷新后重试');
      }
      header('Location: production_order_edit.php?id='.(int)$id.'&ok=1');
      exit;
    } catch (Throwable $e) {
      $err = $e->getMessage();
      // fallthrough to render
    }
  }

  // 保存整单
  if ($action === 'save') {
    if (!$canSave) {
      http_response_code(403);
      require __DIR__ . '/inc/header.php';
      echo '<div class="container-xl"><div class="alert alert-danger">当前角色不允许保存此操作。</div></div>';
      require __DIR__ . '/inc/footer.php';
      exit;
    }

    try {
      $pdo->beginTransaction();

      $order_no       = trim((string)($_POST['order_no'] ?? ''));
      $customer_name  = trim((string)($_POST['customer_name'] ?? ''));
      $scheduled_date = ($_POST['scheduled_date'] ?? '') ?: null;
      $due_date       = ($_POST['due_date'] ?? '') ?: null;
      $note           = trim((string)($_POST['note'] ?? ''));
      $itemsInput     = (array)($_POST['items'] ?? []);

      $status_id_in_form = (int)($_POST['status_id'] ?? 0);
      $want_save_status  = ($status_id_in_form > 0 && isset($statuses[$status_id_in_form]) && $canEditStatus);

      if ($id > 0) {
        // 编辑（乐观锁）
        $version = (int)($_POST['version'] ?? 1);

        if ($want_save_status) {
          $st = $pdo->prepare(
            "UPDATE production_orders
                SET customer_name=?, scheduled_date=?, due_date=?, note=?, status_id=?, version=version+1
              WHERE id=? AND version=?"
          );
          $st->execute([$customer_name, $scheduled_date, $due_date, $note, $status_id_in_form, $id, $version]);
        } else {
          $st = $pdo->prepare(
            "UPDATE production_orders
                SET customer_name=?, scheduled_date=?, due_date=?, note=?, version=version+1
              WHERE id=? AND version=?"
          );
          $st->execute([$customer_name, $scheduled_date, $due_date, $note, $id, $version]);
        }
        if ($st->rowCount() === 0) {
          throw new RuntimeException('数据已被他人修改，请刷新后重试');
        }

        $pdo->prepare("DELETE FROM production_order_items WHERE order_id=?")->execute([$id]);
        if (!empty($itemsInput)) {
          $ins = $pdo->prepare(
            "INSERT INTO production_order_items
               (order_id, product_sku, product_name, spec, color, qty, unit, note)
             VALUES (?,?,?,?,?,?,?,?)"
          );
          foreach ($itemsInput as $it) {
            $sku  = trim((string)($it['product_sku']  ?? ''));
            $name = trim((string)($it['product_name'] ?? ''));
            if ($sku === '' && $name === '') continue;
            $spec = trim((string)($it['spec']  ?? '')) ?: null;
            $color= trim((string)($it['color'] ?? '')) ?: null;
            $qty  = (float)($it['qty'] ?? 0);
            $unit = trim((string)($it['unit'] ?? 'pcs')) ?: 'pcs';
            $inote= trim((string)($it['note'] ?? '')) ?: null;
            $ins->execute([$id, $sku, $name, $spec, $color, $qty, $unit, $inote]);
          }
        }
      } else {
        // 新增
        if ($order_no === '') {
          $order_no = 'PO' . date('YmdHis') . substr((string)mt_rand(100,999), -3);
        }
        $default_status_id = (int)$pdo->query("SELECT id FROM production_statuses ORDER BY sort_order ASC LIMIT 1")->fetchColumn();
        if ($default_status_id <= 0) { throw new RuntimeException('尚未配置生产状态，请先在状态字典中添加'); }
        $use_status_id = $want_save_status ? $status_id_in_form : $default_status_id;

        $st = $pdo->prepare(
          "INSERT INTO production_orders
            (order_no, customer_name, sales_user_id, planner_user_id, status_id, scheduled_date, due_date, note)
           VALUES (?,?,?,?,?,?,?,?)"
        );
        $st->execute([$order_no, $customer_name, current_user_id(), null, $use_status_id, $scheduled_date, $due_date, $note]);
        $id = (int)$pdo->lastInsertId();

        if (!empty($itemsInput)) {
          $ins = $pdo->prepare(
            "INSERT INTO production_order_items
               (order_id, product_sku, product_name, spec, color, qty, unit, note)
             VALUES (?,?,?,?,?,?,?,?)"
          );
          foreach ($itemsInput as $it) {
            $sku  = trim((string)($it['product_sku']  ?? ''));
            $name = trim((string)($it['product_name'] ?? ''));
            if ($sku === '' && $name === '') continue;
            $spec = trim((string)($it['spec']  ?? '')) ?: null;
            $color= trim((string)($it['color'] ?? '')) ?: null;
            $qty  = (float)($it['qty'] ?? 0);
            $unit = trim((string)($it['unit'] ?? 'pcs')) ?: 'pcs';
            $inote= trim((string)($it['note'] ?? '')) ?: null;
            $ins->execute([$id, $sku, $name, $spec, $color, $qty, $unit, $inote]);
          }
        }
      }

      $pdo->commit();
      header('Location: production_order_view.php?id='.$id);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = $e->getMessage();

      // 回显
      $order = $order ?? [];
      $order['order_no']       = $order['order_no']       ?? ($order_no       ?? '');
      $order['customer_name']  = $order['customer_name']  ?? ($customer_name  ?? '');
      $order['scheduled_date'] = $order['scheduled_date'] ?? ($scheduled_date ?? '');
      $order['due_date']       = $order['due_date']       ?? ($due_date       ?? '');
      $order['note']           = $order['note']           ?? ($note           ?? '');
      $items = $itemsInput;
      if (!empty($status_id_in_form)) {
        $order['status_id'] = $status_id_in_form;
        $order['status_name'] = $statuses[$status_id_in_form] ?? ($order['status_name'] ?? '');
      }
    }
  }
}

// ---- 空单默认值（新建）----
if ($id === 0 && !$order) {
  $defaultStatusId = 0;
  if (!empty($statuses)) {
    $keys = array_keys($statuses);
    $defaultStatusId = (int)$keys[0];
  }
  $order = [
    'order_no' => '',
    'customer_name' => '',
    'scheduled_date' => '',
    'due_date' => '',
    'note' => '',
    'version' => 1,
    'status_id' => $defaultStatusId,
    'status_name' => $statuses[$defaultStatusId] ?? '（未创建）',
  ];
  $items = $items ?: [
    ['product_sku'=>'','product_name'=>'','spec'=>'','color'=>'','qty'=>'','unit'=>'pcs','note'=>'']
  ];
}
$versionVal = (int)($order['version'] ?? 1);

// ===== 页面开始 =====
require __DIR__ . '/inc/header.php';
?>
<div class="container-xl">

  <?php if (isset($_GET['ok'])): ?>
    <div class="alert alert-success" role="alert">状态已更新。</div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-danger" role="alert">操作失败：<?= h($err) ?></div>
  <?php endif; ?>

  <div class="page-header d-print-none">
    <div class="row align-items-center">
      <div class="col">
        <h2 class="page-title"><?= $id>0 ? '编辑排产单' : '新建排产单' ?></h2>

        <div class="text-muted mt-1 d-flex align-items-center gap-2">
          <div>当前状态：</div>
          <?php if ($canEditStatus): ?>
            <!-- 独立状态更新表单（权限同 canEdit） -->
            <form method="post" class="d-inline-flex align-items-center gap-2" action="">
              <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="version" value="<?= $versionVal ?>">
              <select class="form-select" name="status_id" style="min-width:220px">
                <?php
                $curSid = (int)($order['status_id'] ?? 0);
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
          <?php if ($id>0): ?>
            <a class="btn btn-outline-primary" href="production_order_view.php?id=<?= (int)$id ?>">查看详情</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <form method="post" action="">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="version" value="<?= $versionVal ?>">

    <div class="row row-cards">
      <!-- 基本信息 -->
      <div class="col-12">
        <div class="card">
          <div class="card-header"><h3 class="card-title">基本信息</h3></div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">排产单号</label>
                <input class="form-control" type="text" name="order_no"
                       value="<?= h($order['order_no'] ?? '') ?>"
                       <?= $id>0 ? 'readonly' : '' ?> <?= $readOnly ? 'disabled' : '' ?>>
                <div class="form-hint">不填将保存时自动生成</div>
              </div>
              <div class="col-md-4">
                <label class="form-label">客户名称</label>
                <input class="form-control" type="text" name="customer_name"
                       value="<?= h($order['customer_name'] ?? '') ?>" <?= $readOnly ? 'disabled' : '' ?>>
              </div>

              <div class="col-md-4">
                <label class="form-label">当前状态</label>
                <?php if ($canEditStatus): ?>
                  <select class="form-select" name="status_id">
                    <?php
                    $curSid = (int)($order['status_id'] ?? 0);
                    foreach ($statuses as $sid => $sname):
                    ?>
                      <option value="<?= (int)$sid ?>" <?= $sid==$curSid?'selected':'' ?>><?= h($sname) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-hint">可在上方“更新状态”即刻生效，也可随整单保存一并生效。</div>
                <?php else: ?>
                  <div><span class="badge bg-blue"><?= h($order['status_name'] ?? '未创建') ?></span></div>
                <?php endif; ?>
              </div>

              <div class="col-md-4">
                <label class="form-label">计划开工日期</label>
                <input class="form-control" type="date" name="scheduled_date"
                       value="<?= h($order['scheduled_date'] ?? '') ?>" <?= $readOnly ? 'disabled' : '' ?>>
              </div>
              <div class="col-md-4">
                <label class="form-label">计划完成日期</label>
                <input class="form-control" type="date" name="due_date"
                       value="<?= h($order['due_date'] ?? '') ?>" <?= $readOnly ? 'disabled' : '' ?>>
              </div>
              <div class="col-12">
                <label class="form-label">备注</label>
                <textarea class="form-control" name="note" rows="4" <?= $readOnly ? 'disabled' : '' ?>><?= h($order['note'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 产品明细 -->
      <div class="col-12">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">产品明细</h3>
            <div class="card-actions">
              <div class="btn-list">
                <button class="btn" type="button" id="btnAdd" <?= $readOnly ? 'disabled' : '' ?>>+ 添加一行</button>
                <button class="btn btn-outline-danger" type="button" id="btnClear" <?= $readOnly ? 'disabled' : '' ?>>清空明细</button>
              </div>
            </div>
          </div>
          <div class="table-responsive">
            <table class="table table-vcenter">
              <thead>
                <tr>
                  <th style="width:12%">SKU</th>
                  <th style="width:20%">名称</th>
                  <th style="width:16%">规格</th>
                  <th style="width:12%">颜色</th>
                  <th class="text-end" style="width:10%">数量</th>
                  <th style="width:10%">单位</th>
                  <th>备注</th>
                  <th class="text-end" style="width:80px">操作</th>
                </tr>
              </thead>
              <tbody id="itemsBody">
              <?php
              $idx = 0;
              foreach ($items as $it):
                $sku   = (string)($it['product_sku']  ?? '');
                $name  = (string)($it['product_name'] ?? '');
                $spec  = (string)($it['spec'] ?? '');
                $color = (string)($it['color'] ?? '');
                $qty   = (string)($it['qty'] ?? '');
                $unit  = (string)($it['unit'] ?? 'pcs');
                $notei = (string)($it['note'] ?? '');
              ?>
                <tr>
                  <td><input class="form-control" type="text" name="items[<?= $idx ?>][product_sku]"  value="<?= h($sku) ?>"   <?= $readOnly ? 'disabled' : '' ?>></td>
                  <td><input class="form-control" type="text" name="items[<?= $idx ?>][product_name]" value="<?= h($name) ?>"  <?= $readOnly ? 'disabled' : '' ?>></td>
                  <td><input class="form-control" type="text" name="items[<?= $idx ?>][spec]"         value="<?= h($spec) ?>"  <?= $readOnly ? 'disabled' : '' ?>></td>
                  <td><input class="form-control" type="text" name="items[<?= $idx ?>][color]"        value="<?= h($color) ?>" <?= $readOnly ? 'disabled' : '' ?>></td>
                  <td><input class="form-control text-end" type="text" name="items[<?= $idx ?>][qty]" value="<?= h($qty) ?>"   <?= $readOnly ? 'disabled' : '' ?>></td>
                  <td><input class="form-control" type="text" name="items[<?= $idx ?>][unit]"         value="<?= h($unit) ?>"  <?= $readOnly ? 'disabled' : '' ?>></td>
                  <td><input class="form-control" type="text" name="items[<?= $idx ?>][note]"         value="<?= h($notei) ?>" <?= $readOnly ? 'disabled' : '' ?>></td>
                  <td class="text-end">
                    <button class="btn btn-outline-danger btn-sm btnDel" type="button" <?= $readOnly ? 'disabled' : '' ?>>删除</button>
                  </td>
                </tr>
              <?php $idx++; endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- 操作区 -->
      <div class="col-12">
        <div class="d-flex gap-2 justify-content-end">
          <?php if ($canSave): ?>
            <button class="btn btn-primary" type="submit">保存</button>
          <?php else: ?>
            <button class="btn" type="button" disabled>只读模式</button>
          <?php endif; ?>
          <a class="btn btn-outline-secondary" href="<?= $id>0 ? 'production_order_view.php?id='.(int)$id : 'production_order_list.php' ?>">取消</a>
        </div>
      </div>
    </div>
  </form>
</div>

<!-- 行模板 -->
<template id="rowTemplate">
  <tr>
    <td><input class="form-control" type="text" name="__REPLACE__[product_sku]"></td>
    <td><input class="form-control" type="text" name="__REPLACE__[product_name]"></td>
    <td><input class="form-control" type="text" name="__REPLACE__[spec]"></td>
    <td><input class="form-control" type="text" name="__REPLACE__[color]"></td>
    <td><input class="form-control text-end" type="text" name="__REPLACE__[qty]"></td>
    <td><input class="form-control" type="text" name="__REPLACE__[unit]" value="pcs"></td>
    <td><input class="form-control" type="text" name="__REPLACE__[note]"></td>
    <td class="text-end"><button class="btn btn-outline-danger btn-sm btnDel" type="button">删除</button></td>
  </tr>
</template>

<script>
(function(){
  const readOnly = <?= $readOnly ? 'true' : 'false' ?>;
  const tbody = document.getElementById('itemsBody');
  const tpl   = document.getElementById('rowTemplate').innerHTML;

  let nextIndex = (function(){
    const last = tbody.querySelector('tr:last-child input[name^="items["]');
    if(!last) return 0;
    const m = last.name.match(/^items\[(\d+)\]/);
    return m ? (parseInt(m[1],10)+1) : 0;
  })();

  function wireRowEvents(tr){
    const btn = tr.querySelector('.btnDel');
    if(btn){
      btn.addEventListener('click', function(){
        tr.parentNode.removeChild(tr);
      }, false);
    }
  }

  Array.from(tbody.querySelectorAll('tr')).forEach(wireRowEvents);

  if(!readOnly){
    const btnAdd   = document.getElementById('btnAdd');
    const btnClear = document.getElementById('btnClear');

    btnAdd?.addEventListener('click', function(){
      const key  = 'items[' + (nextIndex++) + ']';
      const html = tpl.replace(/__REPLACE__/g, key);
      const tmp  = document.createElement('tbody');
      tmp.innerHTML = html.trim();
      const tr   = tmp.firstElementChild;
      tbody.appendChild(tr);
      wireRowEvents(tr);
    }, false);

    btnClear?.addEventListener('click', function(){
      if (confirm('确认清空全部明细？')) { tbody.innerHTML = ''; nextIndex = 0; }
    }, false);
  } else {
    // 只读兜底（状态独立小表单不受影响）
    document.querySelectorAll('[name^="items"], input[name="order_no"], input[name="customer_name"], input[name="scheduled_date"], input[name="due_date"], textarea[name="note"], #btnAdd, #btnClear, .btnDel').forEach(el=>{
      el.setAttribute('disabled','disabled');
    });
  }
})();
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
