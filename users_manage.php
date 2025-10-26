<?php
// users_manage.php
// 登录验证文件固定为 /inc/auth.php；角色字段为 enum('boss','op','sales')；op 文案显示为“库管&排产”。
declare(strict_types=1);

// ===== Debug 开关（?debug=1 时启用）=====
$showDebug = isset($_GET['debug']);
if ($showDebug) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

// ========== 鉴权 ==========
if (is_file(__DIR__ . '/inc/auth.php')) {
  require_once __DIR__ . '/inc/auth.php'; // 内部应已 session_start 并设置 $_SESSION
} else {
  session_start(); // 兜底：仅调试用途
}

// ========== 数据库连接 ==========
require_once __DIR__ . '/db.php';
date_default_timezone_set('Asia/Shanghai');

// ========== 工具函数 ==========
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('tableHasColumn')) {
  function tableHasColumn(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
  }
}
if (!function_exists('firstExistingColumn')) {
  function firstExistingColumn(PDO $pdo, string $table, array $candidates): ?string {
    foreach ($candidates as $c) { if (tableHasColumn($pdo, $table, $c)) return $c; }
    return null;
  }
}

// ========== 角色常量 ==========
if (!defined('ROLE_BOSS'))  define('ROLE_BOSS',  'boss');
if (!defined('ROLE_OP'))    define('ROLE_OP',    'op');
if (!defined('ROLE_SALES')) define('ROLE_SALES', 'sales');

$ROLE_LABELS = [
  ROLE_BOSS  => '老板（总管理员）',
  ROLE_OP    => '库管&排产',
  ROLE_SALES => '销售',
];
$VALID_ROLES = [ROLE_BOSS, ROLE_OP, ROLE_SALES];

// ========== 读取当前用户角色（与 logs_inventory.php 一致的判定方式） ==========
$sessionUser = $_SESSION['user'] ?? null;
$currRole = null;
if (is_array($sessionUser)) {
  $currRole = $sessionUser['role'] ?? ($_SESSION['role'] ?? null);
} elseif (is_object($sessionUser)) {
  $currRole = $sessionUser->role ?? ($_SESSION['role'] ?? null);
} else {
  $currRole = $_SESSION['role'] ?? null;
}
$isBoss = ($currRole === ROLE_BOSS);

// ================== 视图开始：引入 header（无论有无权限都显示导航） ==================
if (is_file(__DIR__ . '/inc/header.php')) {
  include __DIR__ . '/inc/header.php';
} else {
  // 兜底（仅在缺少 header.php 时使用）
  echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>用户管理</title><link rel="stylesheet" href="/style/tabler.min.css"></head><body>';
}

// ===== 权限不足提示（非 boss 用户直接退出；样式与 logs_inventory.php 保持一致） =====
if (!$isBoss) {
  ?>
  <style>
    .center-wrap { min-height: 60vh; display:flex; align-items:center; justify-content:center; }
    .big-deny   { font-size: 42px; font-weight: 800; color:#c92a2a; letter-spacing: .06em; }
    .sub-text   { color:#6b7280; margin-top:10px; }
  </style>
  <div class="center-wrap">
    <div class="text-center">
      <div class="big-deny">权限不足</div>
      <div class="sub-text">仅老板（管理员）可访问用户管理页面</div>
    </div>
  </div>
  <?php
  if (is_file(__DIR__ . '/inc/footer.php')) {
    include __DIR__ . '/inc/footer.php';
  } else {
    echo '</body></html>';
  }
  exit;
}

// ================== 以下仅老板可见的完整功能 ==================

// ========== users 表关键列名 ==========
$tbl = 'users';
$colId         = firstExistingColumn($pdo, $tbl, ['id']) ?? 'id';
$colUsername   = firstExistingColumn($pdo, $tbl, ['username','user_name','account','login']);
$colDispName   = firstExistingColumn($pdo, $tbl, ['display_name','nickname','real_name','name']);
$colRole       = 'role'; // 已知 enum('boss','op','sales')
$colEnabled    = firstExistingColumn($pdo, $tbl, ['is_enabled','enabled','active','status']); // 可选
$colLastLogin  = firstExistingColumn($pdo, $tbl, ['last_login_at','last_login','last_seen_at']);
$colCreatedAt  = firstExistingColumn($pdo, $tbl, ['created_at','created','reg_time','registered_at']);
$colPassword   = firstExistingColumn($pdo, $tbl, ['password','password_hash','passwd','pwd_hash']); // 可选

// ========== CSRF ==========
if (!isset($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];
function check_csrf(): void {
  if (($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('CSRF 校验失败');
  }
}

// ========== 操作处理（仅 boss） ==========
$opMsg = '';
$opErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'toggle_status') {
      if (!$colEnabled) throw new RuntimeException('当前数据表不支持启用/禁用（缺少状态列）');
      $uid = (int)($_POST['user_id'] ?? 0);
      if ($uid <= 0) throw new RuntimeException('参数错误：用户ID');
      if ($uid === (int)($_SESSION['user']['id'] ?? 0)) throw new RuntimeException('不能禁用/启用自己的账号');

      $st = $pdo->prepare("SELECT {$colEnabled} AS enabled_val FROM {$tbl} WHERE {$colId} = ?");
      $st->execute([$uid]);
      $row = $st->fetch();
      if (!$row) throw new RuntimeException('用户不存在');

      $curr = (int)$row['enabled_val'];
      $next = ($curr === 1) ? 0 : 1;

      $up = $pdo->prepare("UPDATE {$tbl} SET {$colEnabled} = ? WHERE {$colId} = ?");
      $up->execute([$next, $uid]);

      $opMsg = ($next === 1) ? '已启用账号' : '已禁用账号';
    }
    elseif ($action === 'change_password') {
      if (!$colPassword) throw new RuntimeException('当前数据表不支持修改密码（缺少密码列）');
      $uid = (int)($_POST['user_id'] ?? 0);
      $pwd = (string)($_POST['new_password'] ?? '');
      if ($uid <= 0) throw new RuntimeException('参数错误：用户ID');
      if ($pwd === '' || strlen($pwd) < 8) throw new RuntimeException('新密码至少 8 位');

      $hash = password_hash($pwd, PASSWORD_DEFAULT);
      $up = $pdo->prepare("UPDATE {$tbl} SET {$colPassword} = ? WHERE {$colId} = ?");
      $up->execute([$hash, $uid]);

      $opMsg = '密码已更新';
    }
    elseif ($action === 'create_user') {
      if (!$colUsername) throw new RuntimeException('新增失败：未检测到用户名列（如 username/user_name/account）。');
      if (!$colRole)     throw new RuntimeException('新增失败：未检测到角色列 role。');
      if (!$colPassword) throw new RuntimeException('新增失败：未检测到密码列（如 password_hash/password）。');

      $newUsername = trim((string)($_POST['new_username'] ?? ''));
      $newDispName = trim((string)($_POST['new_display_name'] ?? ''));
      $newRole     = (string)($_POST['new_role'] ?? '');
      $newPwd      = (string)($_POST['new_password'] ?? '');

      if ($newUsername === '' || strlen($newUsername) < 3) throw new RuntimeException('用户名至少 3 个字符。');
      if (!in_array($newRole, $VALID_ROLES, true))         throw new RuntimeException('员工类型不合法。');
      if ($newPwd === '' || strlen($newPwd) < 8)           throw new RuntimeException('密码至少 8 位。');

      $chk = $pdo->prepare("SELECT COUNT(*) FROM {$tbl} WHERE {$colUsername} = ?");
      $chk->execute([$newUsername]);
      if ((int)$chk->fetchColumn() > 0) throw new RuntimeException('用户名已存在，请更换。');

      $cols = [$colUsername, $colRole, $colPassword];
      $vals = [$newUsername,  $newRole, password_hash($newPwd, PASSWORD_DEFAULT)];

      if ($colDispName) { $cols[] = $colDispName; $vals[] = $newDispName ?: $newUsername; }
      if ($colEnabled)  { $cols[] = $colEnabled;  $vals[] = 1; }
      if ($colCreatedAt){ $cols[] = $colCreatedAt; $vals[] = date('Y-m-d H:i:s'); }

      $placeholders = implode(',', array_fill(0, count($cols), '?'));
      $colList = implode(',', $cols);
      $ins = $pdo->prepare("INSERT INTO {$tbl} ({$colList}) VALUES ({$placeholders})");
      $ins->execute($vals);

      $opMsg = '已新增用户：' . $newUsername;
    }
    else {
      throw new RuntimeException('未知操作');
    }
  } catch (Throwable $e) {
    $opErr = $e->getMessage();
  }
}

// ========== 顶部筛选 ==========
$roleFilter = (string)($_GET['role'] ?? '');
$validRoleValues = [ROLE_BOSS, ROLE_OP, ROLE_SALES];

$where  = '1=1';
$params = [];
if ($roleFilter !== '' && in_array($roleFilter, $validRoleValues, true)) {
  $where .= " AND {$colRole} = ?";
  $params[] = $roleFilter;
}

// ========== 查询 ==========
$selectCols = [
  "{$colId} AS uid",
  ($colUsername ? "{$colUsername} AS u_name" : "'(缺少列)' AS u_name"),
  "{$colRole} AS u_role",
];
if ($colDispName)  $selectCols[] = "{$colDispName} AS d_name";
if ($colEnabled)   $selectCols[] = "{$colEnabled} AS u_enabled";
if ($colLastLogin) $selectCols[] = "{$colLastLogin} AS last_login";
if ($colCreatedAt) $selectCols[] = "{$colCreatedAt} AS created_at";

$selectSql = implode(', ', $selectCols);

$sql = "SELECT {$selectSql} FROM {$tbl} WHERE {$where} ORDER BY {$colRole} ASC, " .
       ($colEnabled ? "{$colEnabled} DESC, " : "") .
       "{$colId} ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$users = $st->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-wide container-xl py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="m-0">用户管理</h2>
    <div><a href="index.php" class="btn btn-outline-secondary">返回首页</a></div>
  </div>

  <?php if ($opMsg): ?><div class="alert alert-success"><?php echo h($opMsg); ?></div><?php endif; ?>
  <?php if ($opErr): ?><div class="alert alert-danger">操作失败：<?php echo h($opErr); ?></div><?php endif; ?>

  <?php if ($showDebug): ?>
    <div class="card mb-3 border-info">
      <div class="card-header"><strong>调试信息（仅在 ?debug=1 时显示）</strong></div>
      <div class="card-body">
        <div class="mb-2"><strong>Session 键：</strong>
          <pre class="debug-pre"><?php echo h(json_encode(array_keys($_SESSION ?? []), JSON_UNESCAPED_UNICODE)); ?></pre>
        </div>
        <div class="mb-2"><strong>Session 摘要：</strong>
          <pre class="debug-pre"><?php
            $show = [
              'role'       => $_SESSION['role'] ?? null,
              'user_has'   => is_array($_SESSION['user'] ?? null) || is_object($_SESSION['user'] ?? null),
            ];
            echo h(json_encode($show, JSON_UNESCAPED_UNICODE));
          ?></pre>
        </div>
        <div class="mb-2"><strong>最终识别角色：</strong> <?php echo h($currRole ?: ''); ?></div>
        <div class="mb-2"><strong>isBoss：</strong> <?php echo $isBoss?'true':'false'; ?></div>
        <div class="mb-2"><strong>列名：</strong>
          <pre class="debug-pre"><?php
            $cols = compact('colId','colUsername','colDispName','colRole','colEnabled','colLastLogin','colCreatedAt','colPassword');
            echo h(json_encode($cols, JSON_UNESCAPED_UNICODE));
          ?></pre>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- ===== 新增用户（仅 boss 可见） ===== -->
  <div class="card mb-3">
    <div class="card-header">
      <div class="card-title">新增用户</div>
    </div>
    <form method="post" class="card-body row g-3">
      <input type="hidden" name="csrf_token" value="<?php echo h($CSRF); ?>">
      <input type="hidden" name="action" value="create_user">

      <div class="col-12 col-md-3">
        <label class="form-label">用户名 <span class="text-danger">*</span></label>
        <input type="text" name="new_username" class="form-control" required minlength="3" maxlength="64" placeholder="登录名">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">显示名称</label>
        <input type="text" name="new_display_name" class="form-control" maxlength="64" placeholder="用于界面展示，可留空">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">员工类型 <span class="text-danger">*</span></label>
        <select name="new_role" class="form-select" required>
          <?php foreach ($VALID_ROLES as $r): ?>
            <option value="<?php echo h($r); ?>"><?php echo h($ROLE_LABELS[$r]); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">账号密码 <span class="text-danger">*</span></label>
        <input type="password" name="new_password" class="form-control" required minlength="8" placeholder="至少 8 位">
      </div>

      <div class="col-12">
        <button type="submit" class="btn btn-primary">保存新增</button>
      </div>
    </form>
    <div class="card-footer text-muted">
      说明：用户名需唯一。显示名称不可用于登录，仅用于备注和显示员工名字。
    </div>
  </div>

  <!-- 顶部筛选（boss/op/sales；op 显示为“库管&排产”） -->
  <form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-12 col-sm-6 col-md-4">
      <label class="form-label">员工类型</label>
      <select name="role" class="form-select">
        <option value="">全部类型</option>
        <?php foreach ([ROLE_BOSS, ROLE_OP, ROLE_SALES] as $val): ?>
          <option value="<?php echo h($val); ?>" <?php echo ($roleFilter===$val?'selected':''); ?>>
            <?php echo h($ROLE_LABELS[$val]); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
      <button type="submit" class="btn btn-primary">筛选</button>
      <a class="btn btn-outline-secondary" href="?">重置</a>
    </div>
  </form>

  <!-- 用户列表 -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">用户列表</div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover card-table">
        <thead>
          <tr>
            <th style="width:80px;">ID</th>
            <th>用户名</th>
            <?php if ($colDispName): ?><th>显示名称</th><?php endif; ?>
            <th style="width:160px;">员工类型</th>
            <?php if ($colEnabled): ?><th style="width:100px;">状态</th><?php endif; ?>
            <?php if ($colLastLogin): ?><th style="width:180px;">最后登录</th><?php endif; ?>
            <?php if ($colCreatedAt): ?><th style="width:180px;">创建时间</th><?php endif; ?>
            <th class="op-col">操作</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
          <tr>
            <td colspan="<?php echo 4 + ($colDispName?1:0)+($colEnabled?1:0)+($colLastLogin?1:0)+($colCreatedAt?1:0); ?>"
                class="text-center text-muted">无数据</td>
          </tr>
        <?php else: foreach ($users as $u): ?>
          <?php
            $uid   = (int)$u['uid'];
            $uname = (string)$u['u_name'];
            $urole = (string)$u['u_role']; // boss/op/sales
            $dname = (string)($u['d_name'] ?? '');
            $state = (int)($u['u_enabled'] ?? 1);
            $last  = (string)($u['last_login'] ?? '—');
            $creat = (string)($u['created_at'] ?? '—');
          ?>
          <tr>
            <td><?php echo $uid; ?></td>
            <td><?php echo h($uname); ?></td>
            <?php if ($colDispName): ?><td><?php echo h($dname); ?></td><?php endif; ?>
            <td><span class="badge"><?php echo h($ROLE_LABELS[$urole] ?? $urole); ?></span></td>
            <?php if ($colEnabled): ?>
              <td>
                <?php if ($state): ?>
                  <span class="status status-green">启用</span>
                <?php else: ?>
                  <span class="status status-red">禁用</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <?php if ($colLastLogin): ?><td><?php echo h($last ?: '—'); ?></td><?php endif; ?>
            <?php if ($colCreatedAt): ?><td><?php echo h($creat ?: '—'); ?></td><?php endif; ?>
            <td class="op-col">
              <?php if ($colEnabled): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo h($CSRF); ?>">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                <button type="submit"
                  class="btn btn-sm <?php echo $state?'btn-outline-danger':'btn-outline-success'; ?>"
                  <?php echo ($uid===(int)($_SESSION['user']['id'] ?? 0))?'disabled':''; ?>>
                  <?php echo $state ? '禁用' : '启用'; ?>
                </button>
              </form>
              <?php endif; ?>

              <?php if ($colPassword): ?>
              <button type="button" class="btn btn-sm btn-outline-primary"
                      data-bs-toggle="modal" data-bs-target="#pwdModal-<?php echo $uid; ?>">
                修改密码
              </button>
              <?php endif; ?>
            </td>
          </tr>

          <?php if ($colPassword): ?>
          <!-- 修改密码弹窗 -->
          <div class="modal modal-blur fade" id="pwdModal-<?php echo $uid; ?>" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title">修改密码：<?php echo h($uname); ?></h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                  <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                    <div class="mb-3">
                      <label class="form-label">新密码（≥8位）</label>
                      <input type="password" name="new_password" class="form-control" minlength="8" required>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>

        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-3 text-muted">
    提示：支持在本页新增用户、启用/禁用与修改密码；不提供删除账号功能。
  </div>
</div>

<?php
// 页脚
if (is_file(__DIR__ . '/inc/footer.php')) {
  include __DIR__ . '/inc/footer.php';
} else {
  echo '</body></html>';
}
