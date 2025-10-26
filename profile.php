<?php
// profile.php —— 个人资料：修改账号、显示名称、密码（仅当前登录用户）
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/inc/header.php'; // 其中已包含 auth.php 并完成会话校验

// ========== 工具函数 ==========
if (!function_exists('bint')) {
  function bint($v){ return (int)!!$v; }
}
function rstr(int $len=24): string {
  return bin2hex(random_bytes((int)max(8, min(64, $len))));
}

// 当前登录用户（从 inc/header.php 中解析的 $u）
$uid = (int)($u['id'] ?? 0);
if ($uid <= 0) {
  http_response_code(403);
  ?>
  <div style="max-width:720px;margin:12vh auto;text-align:center">
    <div style="font-size:32px;color:#d63939;font-weight:700;margin-bottom:8px">权限不足</div>
    <div style="color:#888">当前会话无效或未登录，无法访问此页面。</div>
  </div>
  <?php
  exit;
}

// ========== CSRF ==========
if (empty($_SESSION['csrf_profile'])) {
  $_SESSION['csrf_profile'] = rstr(24);
}
$csrf = $_SESSION['csrf_profile'];

// ========== 读取当前资料 ==========
$st = $pdo->prepare("SELECT id, username, display_name, password_hash, role FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]);
$me = $st->fetch(PDO::FETCH_ASSOC);
if (!$me) {
  http_response_code(404);
  ?>
  <div style="max-width:720px;margin:12vh auto;text-align:center">
    <div style="font-size:32px;color:#d63939;font-weight:700;margin-bottom:8px">权限不足</div>
    <div style="color:#888">未找到当前用户记录。</div>
  </div>
  <?php
  exit;
}

// ========== 处理提交 ==========
$ok = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $token  = $_POST['csrf']   ?? '';

  if (!hash_equals($csrf, (string)$token)) {
    $err = '安全校验失败，请刷新页面后重试。';
  } elseif ($action === 'update_profile') {
    $newUsername    = trim((string)($_POST['username'] ?? ''));
    $newDisplayName = trim((string)($_POST['display_name'] ?? ''));
    $oldPassword    = (string)($_POST['old_password'] ?? '');
    $newPassword    = (string)($_POST['new_password'] ?? '');
    $newPassword2   = (string)($_POST['new_password2'] ?? '');

    if ($newUsername === '') {
      $err = '账号（username）不能为空。';
    } elseif (!preg_match('/^[A-Za-z0-9_\-\.]{3,32}$/', $newUsername)) {
      $err = '账号仅允许字母/数字/下划线/点/连字符，长度3-32。';
    } elseif (mb_strlen($newDisplayName) > 40) {
      $err = '前台显示名称长度不能超过40个字符。';
    }

    // 用户名唯一性检查（排除自己）
    if ($err === '') {
      $st = $pdo->prepare("SELECT COUNT(1) FROM users WHERE username=? AND id<>?");
      $st->execute([$newUsername, $uid]);
      if ((int)$st->fetchColumn() > 0) {
        $err = '该账号已被占用，请更换。';
      }
    }

    $willChangePassword = ($newPassword !== '' || $newPassword2 !== '');
    if ($err === '' && $willChangePassword) {
      if ($newPassword !== $newPassword2) {
        $err = '两次输入的新密码不一致。';
      } elseif (strlen($newPassword) < 6) {
        $err = '新密码长度至少 6 位。';
      } else {
        if (!password_verify($oldPassword, (string)$me['password_hash'])) {
          $err = '当前密码不正确，无法修改新密码。';
        }
      }
    }

    if ($err === '') {
      $pdo->beginTransaction();
      try {
        $st = $pdo->prepare("UPDATE users SET username=?, display_name=? WHERE id=?");
        $st->execute([$newUsername, $newDisplayName !== '' ? $newDisplayName : null, $uid]);

        if ($willChangePassword) {
          $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
          $st = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
          $st->execute([$newHash, $uid]);
        }

        $pdo->commit();
        $ok = '资料已保存' . ($willChangePassword ? '（密码已更新）' : '（未变更密码）') . '。';

        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
          $_SESSION['user']['username'] = $newUsername;
          $_SESSION['user']['display_name'] = $newDisplayName !== '' ? $newDisplayName : null;
        }
        $st = $pdo->prepare("SELECT id, username, display_name, password_hash, role FROM users WHERE id=? LIMIT 1");
        $st->execute([$uid]);
        $me = $st->fetch(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {
        $pdo->rollBack();
        $err = '保存失败：' . $e->getMessage();
      }
    }
  } else {
    $err = '无效的操作。';
  }
}

$valUsername    = (string)($me['username'] ?? '');
$valDisplayName = (string)($me['display_name'] ?? '');
?>
<div class="container" style="max-width:880px;padding-top:18px;padding-bottom:42px">
  <h3 class="mb-3">个人资料</h3>

  <?php if ($ok): ?>
    <div class="alert alert-success" role="alert" style="padding:10px 12px">
      <?= h($ok) ?>
    </div>
  <?php endif; ?>

  <?php if ($err): ?>
    <div class="alert alert-danger" role="alert" style="padding:10px 12px">
      <?= h($err) ?>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="off" novalidate>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="update_profile">

    <div class="card" style="border-radius:12px;overflow:hidden">
      <div class="card-body" style="padding:16px">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">账号（username）</label>
            <input type="text" name="username" class="form-control" required
                   value="<?= h($valUsername) ?>" placeholder="3-32位：字母/数字/_ . -">
          </div>
          <div class="col-md-6">
            <label class="form-label">前台显示名称（可选）</label>
            <input type="text" name="display_name" class="form-control"
                   value="<?= h($valDisplayName) ?>" placeholder="用于页面展示的昵称">
          </div>

          <div class="col-12">
            <hr class="my-2">
            <div class="small text-muted mb-2">如需修改密码，请同时填写下列三项；不修改则留空即可。</div>
          </div>

          <div class="col-md-4">
            <label class="form-label">当前密码</label>
            <input type="password" name="old_password" class="form-control" placeholder="修改密码时必填">
          </div>
          <div class="col-md-4">
            <label class="form-label">新密码</label>
            <input type="password" name="new_password" class="form-control" placeholder="至少6位">
          </div>
          <div class="col-md-4">
            <label class="form-label">确认新密码</label>
            <input type="password" name="new_password2" class="form-control" placeholder="再次输入新密码">
          </div>
        </div>
      </div>

      <div class="card-footer" style="padding:12px 16px;text-align:right">
        <button type="submit" class="btn btn-primary">保存修改</button>
        <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
      </div>
    </div>
  </form>

  <div class="mt-3 small text-muted">
    注：仅支持修改当前登录账号的资料；若员工忘记密码，可由老板账户在“用户管理”中重置。
  </div>
</div>

<?php
require __DIR__ . '/inc/footer.php';
