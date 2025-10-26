<?php
// inc/header.php
declare(strict_types=1);

// 认证：固定引入 /inc/auth.php（其中已 session_start() 并做未登录重定向）
require_once __DIR__ . '/auth.php';

// 常用转义
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// 从会话中解析当前用户（兼容多种键名/结构）
$sessionUser = $_SESSION['user'] ?? null;
$u = [
  'id'       => null,
  'username' => null,
  'email'    => null,
  'role'     => $_SESSION['role'] ?? null,
  'name'     => null, // 展示名
];

if (is_array($sessionUser)) {
  $u['id']       = $sessionUser['id'] ?? $sessionUser['user_id'] ?? $sessionUser['uid'] ?? null;
  $u['username'] = $sessionUser['username'] ?? $sessionUser['account'] ?? null;
  $u['email']    = $sessionUser['email'] ?? null;
  $u['role']     = $sessionUser['role'] ?? $u['role'];
  $u['name']     = $sessionUser['display_name'] ?? $sessionUser['real_name'] ?? $sessionUser['name'] ?? null;
} elseif (is_object($sessionUser)) {
  $u['id']       = $sessionUser->id ?? $sessionUser->user_id ?? $sessionUser->uid ?? null;
  $u['username'] = $sessionUser->username ?? $sessionUser->account ?? null;
  $u['email']    = $sessionUser->email ?? null;
  $u['role']     = $sessionUser->role ?? $u['role'];
  $u['name']     = $sessionUser->display_name ?? $sessionUser->real_name ?? $sessionUser->name ?? null;
} else {
  // 兜底零散键
  $u['id']       = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? $_SESSION['id'] ?? null;
  $u['username'] = $_SESSION['username'] ?? null;
  $u['email']    = $_SESSION['email'] ?? null;
  $u['name']     = $_SESSION['display_name'] ?? $_SESSION['real_name'] ?? $_SESSION['name'] ?? null;
}

$displayName = $u['name'] ?: ($u['username'] ?: ($u['email'] ?: '未登录'));

$ROLE_LABELS = [
  'boss'  => '老板',
  'op'    => '库管&排产',
  'sales' => '销售',
];
$role = (string)($u['role'] ?? '');
$roleLabel = $ROLE_LABELS[$role] ?? null;

// 头像首字母（兼容无 mbstring）
$avatarText = function_exists('mb_substr')
  ? mb_substr($displayName, 0, 1, 'UTF-8')
  : substr($displayName, 0, 1);

// ====== 权限矩阵（只负责“显示/隐藏”，真正鉴权仍用后端校验）======
function can_manage_categories(string $role): bool { return in_array($role, ['boss','op'], true); }
function can_add_products(string $role): bool     { return in_array($role, ['boss','op'], true); }
function can_view_inventory(string $role): bool   { return in_array($role, ['boss','op'], true); }
function can_manage_reserve(string $role): bool   { return in_array($role, ['boss','op'], true); }
function can_manage_users(string $role): bool     { return $role === 'boss'; }
function can_view_logs(string $role): bool        { return $role === 'boss'; } // ★ 新增：仅老板可见“操作日志”

// CSRF 用于登出
$csrf = function_exists('csrf_token') ? csrf_token() : bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1"
  />
  <title>Mini ERP</title>
  <link href="/style/tabler.min.css" rel="stylesheet">
  <style>
    body { background: #f6f8fb; }
    .container-wide { max-width: 1600px; }
    .table th, .table td { vertical-align: middle; }
    .badge-muted { background: #eef2f7; color:#4b5563; }
    .avatar-badge {
      width:28px;height:28px;border-radius:50%;
      background:#e5e7eb;color:#111827; display:flex; align-items:center; justify-content:center;
      font-weight:600; font-size:12px;
    }
  </style>
</head>
<body>
  <div class="page">
    <header class="navbar navbar-expand-md d-print-none">
      <div class="container-xl">
        <a class="navbar-brand" >
          <span class="navbar-brand-text">Mini ERP</span>
        </a>

        <!-- 右侧用户信息 -->
        <div class="navbar-nav flex-row order-md-last">
          <?php if ($u['id']): ?>
            <div class="nav-item dropdown">
              <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown" aria-label="Open user menu">
                <span class="me-2 avatar-badge"><?php echo h($avatarText); ?></span>
                <div class="d-none d-md-block">
                  <div><?php echo h($displayName); ?></div>
                  <?php if ($roleLabel): ?>
                    <div class="small text-muted"><?php echo h($roleLabel); ?></div>
                  <?php endif; ?>
                </div>
              </a>
              <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                <a href="/profile.php" class="dropdown-item">个人资料</a>
                <div class="dropdown-divider"></div>
                <a href="/inc/auth_action.php?action=logout&amp;_csrf=<?php echo h($csrf); ?>" class="dropdown-item text-danger">退出登录</a>
              </div>
            </div>
          <?php else: ?>
            <a class="btn btn-sm btn-primary" href="/login.php">登录</a>
          <?php endif; ?>
        </div>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu">
          <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbar-menu">
          <div class="navbar-nav">
              <a class="nav-link" href="/index.php">首页</a>
            <?php if (can_manage_categories($role)): ?>
              <a class="nav-link" href="/manage_categories.php">分类管理</a>
            <?php endif; ?>

            <?php if (can_add_products($role)): ?>
              <a class="nav-link" href="/products.php">产品新增</a>
            <?php endif; ?>

            <?php if (can_view_inventory($role)): ?>
              <a class="nav-link" href="/inventory.php">库存管理查看</a>
            <?php endif; ?>

            <?php if (can_manage_reserve($role)): ?>
              <a class="nav-link" href="/reservations.php">产品预定</a>
            <?php endif; ?>

            <?php if (can_manage_users($role)): ?>
              <a class="nav-link" href="/users_manage.php">用户管理</a>
            <?php endif; ?>



  <a class="nav-link" href="/production_order_list.php">排产</a>
            <?php if (can_manage_categories($role)): ?>
              <a class="nav-link" href="/production_status_manage.php">排产状态管理</a>
            <?php endif; ?>
            <?php if (can_view_logs($role)): ?>
              <a class="nav-link" href="/logs_inventory.php">操作日志</a>  <!-- ★ 新增入口，仅老板可见 -->
            <?php endif; ?>
          </div>
        </div>
      </div>
    </header>
    <div class="page-wrapper">
      <div class="container-xl container-wide mt-3">
