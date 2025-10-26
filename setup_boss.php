<?php
// setup_boss.php — 初始化创建“老板（boss）”账号（独立页，不使用 auth.php）
declare(strict_types=1);
require __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/** 最小化 CSRF 工具 */
function csrf_token(): string {
  if (empty($_SESSION['_csrf_setup'])) $_SESSION['_csrf_setup'] = bin2hex(random_bytes(16));
  return $_SESSION['_csrf_setup'];
}
function csrf_validate(?string $t): bool {
  return !empty($t) && !empty($_SESSION['_csrf_setup']) && hash_equals($_SESSION['_csrf_setup'], $t);
}

$err = '';
$ok  = '';

/** 1) 确保 users 表存在（如果没有则自动创建） */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      username       VARCHAR(64)  NOT NULL UNIQUE,
      display_name   VARCHAR(128) NOT NULL,
      role           ENUM('boss','op','sales') NOT NULL DEFAULT 'sales',
      password_hash  VARCHAR(255) NOT NULL,
      enabled        TINYINT(1) NOT NULL DEFAULT 1,
      created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  $err = '创建/检查 users 表失败：' . $e->getMessage();
}

/** 2) 检查是否已经存在 boss 账号 */
$bossExists = false;
if (!$err) {
  try {
    $st = $pdo->query("SELECT COUNT(*) FROM users WHERE role='boss'");
    $bossExists = ((int)$st->fetchColumn()) > 0;
  } catch (Throwable $e) {
    $err = '检查 boss 账号失败：' . $e->getMessage();
  }
}

/** 3) 处理提交（仅当不存在 boss 时允许提交） */
if (!$err && !$bossExists && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate($_POST['_csrf'] ?? '')) {
    $err = '非法请求，请刷新页面后重试。';
  } else {
    $username     = trim($_POST['username'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $password     = (string)($_POST['password'] ?? '');
    $password2    = (string)($_POST['password2'] ?? '');

    if ($username === '' || $display_name === '' || $password === '' || $password2 === '') {
      $err = '请完整填写所有字段。';
    } elseif ($password !== $password2) {
      $err = '两次输入的密码不一致。';
    } else {
      // 账号重名检查
      try {
        $st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
        $st->execute([$username]);
        if ($st->fetch()) {
          $err = '该账号已存在，请更换用户名。';
        } else {
          $hash = password_hash($password, PASSWORD_DEFAULT);
          $st = $pdo->prepare("INSERT INTO users(username, display_name, role, password_hash, enabled) VALUES(?,?,?,?,1)");
          $st->execute([$username, $display_name, 'boss', $hash]);
          $ok = '老板账号创建成功！出于安全考虑，请立即删除或重命名本页面（setup_boss.php）。';
          // 旋转 CSRF，避免重复提交
          $_SESSION['_csrf_setup'] = bin2hex(random_bytes(16));
          // 创建成功后再次检测
          $st2 = $pdo->query("SELECT COUNT(*) FROM users WHERE role='boss'");
          $bossExists = ((int)$st2->fetchColumn()) > 0;
        }
      } catch (Throwable $e) {
        $err = '写入账号失败：' . $e->getMessage();
      }
    }
  }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>初始化老板账号</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
  <style>
    body { background: #f5f7fb; }
    .page { min-height: 100vh; display:flex; align-items:center; }
    .card { box-shadow: 0 10px 30px rgba(0,0,0,.04); }
  </style>
</head>
<body>
  <div class="page">
    <div class="container-tight py-4">
      <div class="card card-md">
        <div class="card-header">
          <h3 class="card-title">初始化老板账号</h3>
        </div>
        <div class="card-body">
          <?php if ($err): ?>
            <div class="alert alert-danger"><?= h($err) ?></div>
          <?php endif; ?>

          <?php if ($ok): ?>
            <div class="alert alert-success"><?= h($ok) ?></div>
          <?php endif; ?>

          <?php if ($bossExists): ?>
            <div class="alert alert-info">
              已检测到系统中存在 <strong>boss</strong> 账号。为安全起见，本页面不再允许创建新的老板账号。<br>
              如需新增或重置，请用当前老板账号登录后台进行操作。
            </div>
          <?php else: ?>
            <form method="post" class="row g-3">
              <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
              <div class="col-12">
                <label class="form-label">账号（用户名）</label>
                <input class="form-control" name="username" required autofocus>
              </div>
              <div class="col-12">
                <label class="form-label">显示名称</label>
                <input class="form-control" name="display_name" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">密码</label>
                <input class="form-control" type="password" name="password" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">确认密码</label>
                <input class="form-control" type="password" name="password2" required>
              </div>
              <div class="col-12">
                <button class="btn btn-primary w-100">创建老板账号</button>
              </div>
            </form>
            <div class="text-secondary small mt-3">
              提示：创建成功后，请删除或重命名 <code>setup_boss.php</code>，避免被他人再次访问。
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
