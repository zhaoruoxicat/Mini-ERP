<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/inc/auth.php';

// 已登录则跳首页
if (is_logged_in()) { header('Location: /'); exit; }

$res = handle_login($pdo);
$err = $res['error'] ?? '';

$redirect = $_GET['redirect'] ?? ($_POST['redirect'] ?? '/');
$csrf = csrf_token();

include __DIR__ . '/inc/header.php';
?>
<div class="row justify-content-center">
  <div class="col-md-4">
    <div class="card">
      <div class="card-header"><h3 class="card-title">登录</h3></div>
      <div class="card-body">
        <?php if ($err): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="login">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">
          <div class="mb-3">
            <label class="form-label">账号</label>
            <input class="form-control" name="username" autofocus required>
          </div>
          <div class="mb-3">
            <label class="form-label">密码</label>
            <input class="form-control" name="password" type="password" required>
          </div>
          <button class="btn btn-primary w-100">登录</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
