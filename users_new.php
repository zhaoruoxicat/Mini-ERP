<?php
// users_new.php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/inc/auth.php';

require_role(ROLE_BOSS); // 仅老板

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate($_POST['_csrf'] ?? '')) {
    $err = '非法请求，请刷新后重试。';
  } else {
    $username     = trim($_POST['username'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $role         = $_POST['role'] ?? ROLE_SALES;
    $enabled      = isset($_POST['enabled']) ? 1 : 0;
    $pwd          = (string)($_POST['password'] ?? '');
    $pwd2         = (string)($_POST['password2'] ?? '');

    if ($username === '' || $display_name === '' || $pwd === '' || $pwd2 === '') {
      $err = '请完整填写表单。';
    } elseif ($pwd !== $pwd2) {
      $err = '两次输入的密码不一致。';
    } elseif (!in_array($role, [ROLE_BOSS, ROLE_OP, ROLE_SALES], true)) {
      $err = '无效的角色选项。';
    } else {
      // 查重
      $st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
      $st->execute([$username]);
      if ($st->fetch()) {
        $err = '该账号已存在。';
      } else {
        $hash = password_hash($pwd, PASSWORD_DEFAULT);
        $st = $pdo->prepare("INSERT INTO users(username, display_name, role, password_hash, enabled) VALUES(?,?,?,?,?)");
        $st->execute([$username, $display_name, $role, $hash, $enabled]);
        $ok = '用户创建成功。';
      }
    }
  }
}

$csrf = csrf_token();
include __DIR__ . '/inc/header.php';
?>
<div class="row row-cards">
  <div class="col-md-6 col-lg-5">
    <div class="card">
      <div class="card-header"><h3 class="card-title">新建用户</h3></div>
      <div class="card-body">
        <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($ok):  ?><div class="alert alert-success"><?= htmlspecialchars($ok,  ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <form method="post" class="row g-2">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <div class="col-12">
            <label class="form-label">账号（用户名）</label>
            <input class="form-control" name="username" required>
          </div>
          <div class="col-12">
            <label class="form-label">显示名称</label>
            <input class="form-control" name="display_name" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">密码</label>
            <input class="form-control" name="password" type="password" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">确认密码</label>
            <input class="form-control" name="password2" type="password" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">角色</label>
            <select class="form-select" name="role" required>
              <option value="<?= ROLE_BOSS ?>">老板（总管理员）</option>
              <option value="<?= ROLE_OP ?>">库管&排产</option>
              <option value="<?= ROLE_SALES ?>" selected>销售</option>
            </select>
          </div>
          <div class="col-md-6 d-flex align-items-end">
            <label class="form-check">
              <input class="form-check-input" type="checkbox" name="enabled" checked>
              <span class="form-check-label">启用账号</span>
            </label>
          </div>
          <div class="col-12">
            <button class="btn btn-primary">保存</button>
            <a class="btn btn-outline-secondary" href="index.php">返回</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- 可选：右侧权限说明 -->
  <div class="col-md-6 col-lg-7">
    <div class="card">
      <div class="card-header"><h3 class="card-title">权限说明</h3></div>
      <div class="card-body text-secondary">
        <ul class="mb-0">
          <li><strong>老板（boss）</strong>：最大权限；新建/修改用户；重置密码；启用/禁用账号；可访问所有页面。</li>
          <li><strong>库管&排产（op）</strong>：查看并修改库存信息、录入流水、处理预定。</li>
          <li><strong>销售（sales）</strong>：仅可查看首页库存视图（index）。</li>
        </ul>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/inc/footer.php'; ?>
