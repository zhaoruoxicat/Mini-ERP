<?php
// inc/auth.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/** 角色常量 */
const ROLE_BOSS  = 'boss';   // 老板/总管理员
const ROLE_OP    = 'op';     // 库管&排产
const ROLE_SALES = 'sales';  // 销售（只看首页库存）

/** 生成/获取 CSRF Token */
function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['_csrf'];
}
function csrf_validate(?string $t): bool {
  return !empty($t) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $t);
}

/** 当前登录用户（数组）或 null */
function current_user(): ?array {
  return $_SESSION['user'] ?? null;
}

/** 是否已登录 */
function is_logged_in(): bool {
  return !empty($_SESSION['user']);
}

/** 检查角色：$allowed 可以是字符串或字符串数组 */
function require_role($allowed): void {
  if (!is_logged_in()) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/')); exit;
  }
  $role = $_SESSION['user']['role'] ?? '';
  $ok = is_array($allowed) ? in_array($role, $allowed, true) : ($role === $allowed);
  if (!$ok) {
    http_response_code(403);
    echo '<h2 style="padding:24px">403 无权限访问</h2>';
    exit;
  }
}

/** 要求登录（不限制角色） */
function require_login(): void {
  if (!is_logged_in()) {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/')); exit;
  }
}

/** 登录逻辑：验证账号、密码与启用状态 */
function handle_login(PDO $pdo): array {
  $err = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!csrf_validate($_POST['_csrf'] ?? '')) {
      $err = '非法请求，请刷新后重试。';
      return ['error'=>$err];
    }

    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
      $err = '请输入账号与密码。';
      return ['error'=>$err];
    }

    $st = $pdo->prepare("SELECT * FROM users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u || !password_verify($password, (string)$u['password_hash'])) {
      $err = '账号或密码不正确。';
      return ['error'=>$err];
    }
    if (!(int)$u['enabled']) {
      $err = '账号已被禁用，请联系管理员。';
      return ['error'=>$err];
    }

    // 登录成功
    $_SESSION['user'] = [
      'id'           => (int)$u['id'],
      'username'     => $u['username'],
      'display_name' => (string)($u['display_name'] ?? ''),
      'role'         => (string)$u['role'],
    ];
    // 旋转 CSRF
    $_SESSION['_csrf'] = bin2hex(random_bytes(16));

    $redirect = $_POST['redirect'] ?? '/';
    header('Location: ' . ($redirect !== '' ? $redirect : '/')); exit;
  }
  return ['error'=>$err];
}

/** 登出 */
function handle_logout(): void {
  if (($_GET['action'] ?? '') === 'logout') {
    if (!csrf_validate($_GET['_csrf'] ?? '')) {
      http_response_code(400);
      echo 'Bad Request'; exit;
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"], $params["secure"], $params["httponly"]
      );
    }
    session_destroy();
    header('Location: /login.php'); exit;
  }
}

/** 顶部右上角用户信息/登出链接（可选） */
function render_user_widget(): void {
  $u = current_user();
  if (!$u) return;
  $name = htmlspecialchars($u['display_name'] ?: $u['username'], ENT_QUOTES, 'UTF-8');
  $csrf = csrf_token();
  echo <<<HTML
  <div class="ms-auto">
    <span class="me-3 text-secondary">{$name}（{$u['role']}）</span>
    <a class="btn btn-outline-secondary btn-sm" href="/inc/auth_action.php?action=logout&_csrf={$csrf}">退出</a>
  </div>
HTML;
}

/* ------------------------------------------------------------------
 *  关键改动：全局访问拦截（白名单外页面必须登录）
 *  说明：
 *   - 任何页面只要 include/require 本文件，就会触发下面的拦截。
 *   - 白名单里通常放：登录页、初始化老板账号页、登出动作页等。
 * ------------------------------------------------------------------ */
$__script = $_SERVER['SCRIPT_NAME'] ?? '';
$__path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: $__script;

/** 允许未登录访问的路径（按需增减） */
$__public_whitelist = [
  '/login.php',
  '/inc/auth_action.php',   // 专用登出路由页（见下方）
  '/setup_boss.php',        // 初次创建老板账号页面（你之前要求不登录可访问）
];

/** 若当前不在白名单且未登录，则强制跳到登录页 */
if (!is_logged_in() && !in_array($__path, $__public_whitelist, true)) {
  header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/'));
  exit;
}

/* ------------------------------------------------------------------
 *  可选：将 /inc/auth_action.php 作为登出路由文件
 *  只需在 /inc/auth_action.php 顶部： require __DIR__.'/auth.php';
 *  即可复用这里的 handle_logout()
 * ------------------------------------------------------------------ */
if (basename($__path) === 'auth_action.php') {
  handle_logout();
  exit;
}
