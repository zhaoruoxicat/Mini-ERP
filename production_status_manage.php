<?php
// production_status_manage.php —— 生产状态管理（字典表）
// 表：production_statuses(id, name, key_name, sort_order, is_final)
// 依赖：/inc/auth.php（登录与 CSRF）、/inc/header.php、/inc/footer.php、db.php（PDO）

declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/inc/auth.php';
date_default_timezone_set('Asia/Shanghai');

// 工具
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function bint($v): int { return (int)!!$v; }

// 权限控制：boss/op 可管理；sales 显示权限不足
$u = current_user();
$role = $u['role'] ?? 'sales';
$allowed = [ROLE_BOSS, ROLE_OP];

if (!in_array($role, $allowed, true)) {
  require __DIR__ . '/inc/header.php';
  echo '<div class="container-xl py-6"><div class="text-center">'
     . '<div style="font-size:28px;color:#d63939;font-weight:700;letter-spacing:.5px;">权限不足</div>'
     . '<div style="margin-top:8px;color:#666;">抱歉，你没有访问本页面的权限。若有疑问请联系管理员。</div>'
     . '</div></div>';
  require __DIR__ . '/inc/footer.php';
  exit;
}

$ok = '';
$err = '';

// 处理动作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if (!csrf_validate($_POST['_csrf'] ?? '')) {
    $err = 'CSRF 校验失败，请刷新页面后重试。';
  } else {
    try {
      if ($action === 'add') {
        $name       = trim((string)($_POST['name'] ?? ''));
        $key_name   = trim((string)($_POST['key_name'] ?? ''));
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_final   = bint($_POST['is_final'] ?? 0);

        if ($name === '' || $key_name === '') {
          throw new RuntimeException('名称与键名不能为空。');
        }

        // 唯一性提示（不强制，但给出人性化报错；如需强制可在 DB 加 UNIQUE）
        $chk = $pdo->prepare("SELECT COUNT(1) FROM production_statuses WHERE key_name=?");
        $chk->execute([$key_name]);
        if ((int)$chk->fetchColumn() > 0) {
          throw new RuntimeException('键名已存在，请更换。');
        }

        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO production_statuses(name, key_name, sort_order, is_final) VALUES(?,?,?,?)");
        $ins->execute([$name, $key_name, $sort_order, $is_final]);
        $pdo->commit();
        $ok = '新增成功。';
      }
      elseif ($action === 'update') {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim((string)($_POST['name'] ?? ''));
        $key_name   = trim((string)($_POST['key_name'] ?? ''));
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_final   = bint($_POST['is_final'] ?? 0);

        if ($id <= 0)                  throw new RuntimeException('参数错误：缺少ID。');
        if ($name === '' || $key_name==='') throw new RuntimeException('名称与键名不能为空。');

        $chk = $pdo->prepare("SELECT COUNT(1) FROM production_statuses WHERE key_name=? AND id<>?");
        $chk->execute([$key_name, $id]);
        if ((int)$chk->fetchColumn() > 0) {
          throw new RuntimeException('键名已被其他状态占用。');
        }

        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE production_statuses SET name=?, key_name=?, sort_order=?, is_final=? WHERE id=?");
        $upd->execute([$name, $key_name, $sort_order, $is_final, $id]);
        $pdo->commit();
        $ok = '保存成功。';
      }
      elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('参数错误：缺少ID。');

        // 引用检查：若已有生产单引用该状态，禁止删除
        $ref = $pdo->prepare("SELECT COUNT(1) FROM production_orders WHERE status_id=?");
        $ref->execute([$id]);
        if ((int)$ref->fetchColumn() > 0) {
          throw new RuntimeException('该状态已被生产单引用，无法删除。');
        }

        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM production_statuses WHERE id=?");
        $del->execute([$id]);
        $pdo->commit();
        $ok = '删除成功。';
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = $e->getMessage();
    }
  }
}

// 查询数据
$rows = $pdo->query("SELECT id, name, key_name, sort_order, is_final FROM production_statuses ORDER BY sort_order ASC, id ASC")
            ->fetchAll(PDO::FETCH_ASSOC);

// 页面开始
require __DIR__ . '/inc/header.php';
?>
<div class="container-xl">

  <div class="page-header d-print-none">
    <div class="row align-items-center">
      <div class="col">
        <h2 class="page-title">生产状态管理</h2>
        <div class="text-muted mt-1">配置生产状态字典（排序越小越靠前；终结态用于标记流程完结）。</div>
      </div>
      <div class="col-auto ms-auto d-print-none">
        <a class="btn" href="production_order_list.php">返回排产列表</a>
      </div>
    </div>
  </div>

  <?php if ($ok): ?>
    <div class="alert alert-success" role="alert"><?= h($ok) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-danger" role="alert"><?= h($err) ?></div>
  <?php endif; ?>

  <!-- 新增 -->
  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">新增状态</h3></div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <div class="col-12 col-md-3">
          <label class="form-label">名称<span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" placeholder="例如：待排产" required>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">键名<span class="text-danger">*</span></label>
          <input type="text" name="key_name" class="form-control" placeholder="例如：pending" required>
          <div class="form-hint">建议英文小写 + 下划线</div>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">排序</label>
          <input type="number" name="sort_order" class="form-control" value="10">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">终结态</label>
          <label class="form-check form-switch d-block mt-1">
            <input class="form-check-input" type="checkbox" name="is_final" value="1">
            <span class="form-check-label">到此完结</span>
          </label>
        </div>
        <div class="col-12 col-md-2 d-flex align-items-end">
          <button class="btn btn-primary w-100" type="submit">新增</button>
        </div>
      </form>
    </div>
  </div>

  <!-- 列表 -->
  <div class="card">
    <div class="card-header"><h3 class="card-title">状态列表</h3></div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table mb-0">
        <thead>
          <tr>
            <th style="width:70px;">ID</th>
            <th>名称</th>
            <th>键名</th>
            <th style="width:120px;">排序</th>
            <th style="width:120px;">终结态</th>
            <th class="text-end" style="width:160px;">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">暂无数据</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td class="text-muted"><?= (int)$r['id'] ?></td>
              <td>
                <form method="post" class="d-flex gap-2">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="text" name="name" class="form-control" value="<?= h($r['name']) ?>">
              </td>
              <td>
                  <input type="text" name="key_name" class="form-control" value="<?= h($r['key_name']) ?>">
              </td>
              <td style="max-width:140px;">
                  <input type="number" name="sort_order" class="form-control" value="<?= (int)$r['sort_order'] ?>">
              </td>
              <td>
                  <label class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" name="is_final" value="1" <?= ((int)$r['is_final'] ? 'checked' : '') ?>>
                    <span class="form-check-label">完结</span>
                  </label>
              </td>
              <td class="text-end">
                  <button class="btn btn-sm btn-primary" type="submit">保存</button>
                </form>
                <form method="post" class="d-inline-block"
                      onsubmit="return confirm('确认删除该状态？此操作不可撤销。');">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">删除</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer text-muted">
      提示：若某状态已被生产单引用，将无法删除；可通过修改名称/排序进行调整。
    </div>
  </div>

</div>
<?php require __DIR__ . '/inc/footer.php'; ?>
