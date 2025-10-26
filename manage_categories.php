<?php
// manage_categories.php
// 分类与子分类管理：老板 和 库管&排产 可访问；无权限时使用统一的“权限不足”提示样式
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/inc/auth.php';

require_login(); // 必须登录，后续在页面内做角色检查（以便输出统一提示样式）

// ---------- 角色与权限（统一样式与判定） ----------
if (!defined('ROLE_BOSS'))  define('ROLE_BOSS',  'boss');
if (!defined('ROLE_OP'))    define('ROLE_OP',    'op');
if (!defined('ROLE_SALES')) define('ROLE_SALES', 'sales');

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
      <div class="sub-text">仅老板或库管&排产可访问分类管理页面</div>
    </div>
  </div>
  <?php
  include __DIR__ . '/inc/footer.php';
  exit;
}

// ---------- 工具 ----------
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// ---------- 动作处理 ----------
$err = null;
$action = $_POST['action'] ?? null;

try {
  if ($action === 'add_category') {
    $name = trim($_POST['name'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($name !== '') {
      $st = $pdo->prepare("INSERT INTO categories(name, sort_order) VALUES(?,?)");
      $st->execute([$name, $sort]);
    }
  } elseif ($action === 'edit_category') {
    $id   = (int)$_POST['id'];
    $name = trim($_POST['name'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($id>0 && $name!=='') {
      $st = $pdo->prepare("UPDATE categories SET name=?, sort_order=? WHERE id=?");
      $st->execute([$name, $sort, $id]);
    }
  } elseif ($action === 'delete_category') {
    $id = (int)$_POST['id'];
    if ($id>0) {
      $st = $pdo->prepare("DELETE FROM categories WHERE id=?");
      $st->execute([$id]);
    }
  } elseif ($action === 'add_subcategory') {
    $category_id = (int)$_POST['category_id'];
    $name = trim($_POST['name'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($category_id>0 && $name!=='') {
      $st = $pdo->prepare("INSERT INTO subcategories(category_id,name,sort_order) VALUES(?,?,?)");
      $st->execute([$category_id, $name, $sort]);
    }
  } elseif ($action === 'edit_subcategory') {
    $id   = (int)$_POST['id'];
    $name = trim($_POST['name'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);
    if ($id>0 && $name!=='') {
      $st = $pdo->prepare("UPDATE subcategories SET name=?, sort_order=? WHERE id=?");
      $st->execute([$name, $sort, $id]);
    }
  } elseif ($action === 'delete_subcategory') {
    $id = (int)$_POST['id'];
    if ($id>0) {
      $st = $pdo->prepare("DELETE FROM subcategories WHERE id=?");
      $st->execute([$id]);
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

// ---------- 查询数据 ----------
$cats = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC")->fetchAll();
$subByCat = [];
if ($cats) {
  $ids = array_column($cats, 'id');
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM subcategories WHERE category_id IN ($in) ORDER BY sort_order ASC, id ASC");
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) {
      $subByCat[$row['category_id']][] = $row;
    }
  }
}
?>
<?php if (!empty($err)): ?>
  <div class="alert alert-danger"><?= h($err) ?></div>
<?php endif; ?>

<div class="row row-cards">
  <div class="col-12">
    <div class="card">
      <div class="card-header"><h3 class="card-title">分类管理</h3></div>
      <div class="card-body">
        <form method="post" class="row g-2 mb-3">
          <input type="hidden" name="action" value="add_category">
          <div class="col-md-4">
            <input class="form-control" name="name" placeholder="新增分类名称" required>
          </div>
          <div class="col-md-2">
            <input class="form-control" name="sort_order" type="number" placeholder="排序(数字)">
          </div>
          <div class="col-md-2">
            <button class="btn btn-primary w-100" type="submit">添加分类</button>
          </div>
        </form>

        <?php foreach ($cats as $c): ?>
          <div class="card mb-3">
            <div class="card-body">
              <form method="post" class="row g-2 align-items-center">
                <input type="hidden" name="action" value="edit_category">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <div class="col-md-5">
                  <input class="form-control" name="name" value="<?= h($c['name']) ?>" required>
                </div>
                <div class="col-md-2">
                  <input class="form-control" name="sort_order" type="number" value="<?= (int)$c['sort_order'] ?>">
                </div>
                <div class="col-md-2">
                  <button class="btn btn-outline-primary w-100" type="submit">保存</button>
                </div>
              </form>
              <form method="post" class="mt-2">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-outline-danger btn-sm" onclick="return confirm('删除分类会连带删除其子分类，确定？');">删除分类</button>
              </form>

              <hr>

              <h4 class="mb-2">子分类</h4>
              <form method="post" class="row g-2 mb-2">
                <input type="hidden" name="action" value="add_subcategory">
                <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                <div class="col-md-5">
                  <input class="form-control" name="name" placeholder="新增子分类名称" required>
                </div>
                <div class="col-md-2">
                  <input class="form-control" name="sort_order" type="number" placeholder="排序">
                </div>
                <div class="col-md-2">
                  <button class="btn btn-primary w-100" type="submit">添加子分类</button>
                </div>
              </form>

              <div class="table-responsive">
                <table class="table table-vcenter">
                  <thead><tr><th>ID</th><th>名称</th><th>排序</th><th>操作</th></tr></thead>
                  <tbody>
                  <?php foreach ($subByCat[$c['id']] ?? [] as $s): ?>
                    <tr>
                      <td class="text-muted"><?= (int)$s['id'] ?></td>
                      <td>
                        <form method="post" class="row g-2 align-items-center">
                          <input type="hidden" name="action" value="edit_subcategory">
                          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                          <div class="col-md-6">
                            <input class="form-control" name="name" value="<?= h($s['name']) ?>" required>
                          </div>
                          <div class="col-md-3">
                            <input class="form-control" name="sort_order" type="number" value="<?= (int)$s['sort_order'] ?>">
                          </div>
                          <div class="col-md-3">
                            <button class="btn btn-outline-primary w-100" type="submit">保存</button>
                          </div>
                        </form>
                        <form method="post" class="mt-1">
                          <input type="hidden" name="action" value="delete_subcategory">
                          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                          <button class="btn btn-outline-danger btn-sm" onclick="return confirm('删除子分类？');">删除</button>
                        </form>
                      </td>
                      <td><?= (int)$s['sort_order'] ?></td>
                      <td></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

            </div>
          </div>
        <?php endforeach; ?>

      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>
