<?php
// install/install.php
declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Shanghai');

$root          = dirname(__DIR__);
$lockFile      = __DIR__ . '/install.lock';
$templateFile  = __DIR__ . '/template.db.php';
$targetDbPhp   = $root . '/db.php';
$schemaFile    = __DIR__ . '/erp.sql'; // ← 把 erp.sql 放到 /install 目录

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_check(){ if(($_POST['csrf']??'')!==($_SESSION['csrf']??'')) exit('CSRF 校验失败'); }

function pdo_connect($host,$port,$name,$user,$pass): PDO {
  $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
  $opt = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];
  return new PDO($dsn, $user, $pass, $opt);
}

function write_db_php($tpl, $dest, $vars){
  $content = file_get_contents($tpl);
  if ($content===false) throw new RuntimeException('读取 db.php 模板失败');
  $content = str_replace(
    ['{{DB_HOST}}','{{DB_PORT}}','{{DB_NAME}}','{{DB_USER}}','{{DB_PASS}}'],
    [$vars['host'],$vars['port'],$vars['name'],$vars['user'],$vars['pass']],
    $content
  );
  if (file_put_contents($dest, $content, LOCK_EX)===false) throw new RuntimeException('写入 db.php 失败，请检查根目录写权限');
  @chmod($dest, 0640);
}

// 逐行解析并执行 SQL 文件（支持 DELIMITER），幂等导入 + 兼容 mysqldump 视图占位表
// - 解包版本条件注释：把形如 /*!50001  ...  *\/ 的内容提取为可执行 SQL（注意此处故意打断 */ 序列）
// - 跳过空表占位：CREATE TABLE xxx ( )
// - CREATE TABLE → IF NOT EXISTS
// - CREATE VIEW → OR REPLACE VIEW
// - 忽略常见“已存在/不存在”类非致命错误
// - 可选移除 DEFINER=... 避免账号不匹配
function import_sql_with_delimiters(PDO $pdo, string $sqlFile, bool $stripDefiner = true): void {
  if (!is_file($sqlFile)) throw new RuntimeException("SQL 文件不存在：{$sqlFile}");
  $raw = file_get_contents($sqlFile);
  if ($raw === false) throw new RuntimeException("无法读取 SQL 文件：{$sqlFile}");

  // 解包版本条件注释：/ *!50001  ...  * / → 取中间部分（此处空格是为了避免在注释里出现 */ 序列）
  $raw = preg_replace('/\/\*![0-9]+\s(.*?)\*\//s', '$1', $raw) ?? $raw;

  // 放入内存流，沿用逐行 + DELIMITER 解析
  $fh = fopen('php://temp', 'r+');
  fwrite($fh, $raw);
  rewind($fh);

  $pdo->exec("SET NAMES utf8mb4");
  // 如需放宽模式，可按需开启：
  // $pdo->exec("SET sql_mode=''");

  $delimiter = ';';
  $buffer = '';

  // 可忽略错误号
  $forgivableErrnos = [
    1050, // 表/视图已存在
    1060, // 列已存在
    1061, // 索引已存在
    1091, // 删除的列/键不存在
    1304, // 触发器已存在
    1360, // 触发器已存在（变体）
    1347, // 目标不是表/视图（占位清理时可能遇到）
  ];

  $rewriteCreateTable = static function(string $sql): string {
    return preg_replace(
      '/^\s*CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)(`?[\w]+`?)/i',
      'CREATE TABLE IF NOT EXISTS $1',
      $sql, 1
    ) ?? $sql;
  };

  $rewriteCreateView = static function(string $sql): string {
    return preg_replace(
      '/^\s*CREATE\s+VIEW\s+/i',
      'CREATE OR REPLACE VIEW ',
      $sql, 1
    ) ?? $sql;
  };

  $isEmptyCreateTable = static function(string $sql): bool {
    // 仅匹配：CREATE TABLE xxx (   ) —— 括号里全是空白
    return (bool)preg_match('/^\s*CREATE\s+TABLE\s+`?[\w]+`?\s*\(\s*\)\s*$/i', trim($sql));
  };

  while (($line = fgets($fh)) !== false) {
    $trim = ltrim($line);

    // 跳过空行
    if ($trim === '' || $trim === "\n" || $trim === "\r\n") continue;

    // 单行注释
    if (preg_match('/^(--|#)/', $trim)) continue;

    // 多行注释块
    if (preg_match('/^\/\*/', $trim)) {
      if (strpos($trim, '*/') === false) {
        while (($line2 = fgets($fh)) !== false) {
          if (strpos($line2, '*/') !== false) break;
        }
      }
      continue;
    }

    // 切换 DELIMITER
    if (stripos($trim, 'DELIMITER ') === 0) {
      $parts = preg_split('/\s+/', trim($trim), 2);
      $delimiter = rtrim($parts[1] ?? ';');
      continue;
    }

    // 累加
    $buffer .= $line;

    // 到达当前 delimiter 结尾则执行
    if (substr(rtrim($buffer), -strlen($delimiter)) === $delimiter) {
      $statement = substr(rtrim($buffer), 0, -strlen($delimiter));

      // 清理 DEFINER
      if ($stripDefiner) {
        $statement = preg_replace('/DEFINER=`?[^`]+`?@`?[^`]+`?/i', '', $statement) ?? $statement;
      }

      // 幂等改写
      if (preg_match('/^\s*CREATE\s+TABLE\s+/i', $statement)) {
        // 跳过空表占位
        if ($isEmptyCreateTable($statement)) { $buffer = ''; continue; }
        $statement = $rewriteCreateTable($statement);
      } elseif (preg_match('/^\s*CREATE\s+VIEW\s+/i', $statement)) {
        $statement = $rewriteCreateView($statement);
      }

      $statement = trim($statement);
      if ($statement !== '') {
        try {
          $pdo->exec($statement);
        } catch (PDOException $e) {
          $errno = (int)($e->errorInfo[1] ?? 0);
          $msg   = (string)$e->getMessage();
          $isTriggerExistsText = (bool)preg_match('/trigger .* exists|already exists/i', $msg);
          if (in_array($errno, $forgivableErrnos, true) || $isTriggerExistsText) {
            // 忽略非致命，继续
          } else {
            $snippet = mb_substr($statement, 0, 200);
            throw new RuntimeException("执行 SQL 失败：{$e->getMessage()}\n片段：{$snippet}");
          }
        }
      }
      $buffer = '';
    }
  }
  fclose($fh);

  // 文件末尾残留
  $last = trim($buffer);
  if ($last !== '') {
    if ($stripDefiner) {
      $last = preg_replace('/DEFINER=`?[^`]+`?@`?[^`]+`?/i', '', $last) ?? $last;
    }
    if ($isEmptyCreateTable($last)) return;
    if (preg_match('/^\s*CREATE\s+TABLE\s+/i', $last)) $last = $rewriteCreateTable($last);
    if (preg_match('/^\s*CREATE\s+VIEW\s+/i',  $last)) $last = $rewriteCreateView($last);
    try {
      $pdo->exec($last);
    } catch (PDOException $e) {
      $errno = (int)($e->errorInfo[1] ?? 0);
      $msg   = (string)$e->getMessage();
      $isTriggerExistsText = (bool)preg_match('/trigger .* exists|already exists/i', $msg);
      if (!in_array($errno, $forgivableErrnos, true) && !$isTriggerExistsText) throw $e;
    }
  }
}

function ensure_enabled_alias(PDO $pdo): void {
  // 若 users 表用 enabled，这里补一个 is_enabled 以兼容页面代码
  $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
  $hasEnabled   = in_array('enabled', $cols, true);
  $hasIsEnabled = in_array('is_enabled', $cols, true);

  if ($hasEnabled && !$hasIsEnabled) {
    $pdo->exec("ALTER TABLE users ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash");
    $pdo->exec("UPDATE users SET is_enabled = enabled");
    // 同步触发器（失败不致命）
    try {
      $pdo->exec("DROP TRIGGER IF EXISTS trg_users_sync_enabled_insert");
      $pdo->exec("DROP TRIGGER IF EXISTS trg_users_sync_enabled_update");
      $pdo->exec("
        CREATE TRIGGER trg_users_sync_enabled_insert BEFORE INSERT ON users
        FOR EACH ROW SET NEW.is_enabled = COALESCE(NEW.is_enabled, NEW.enabled, 1), NEW.enabled = COALESCE(NEW.enabled, NEW.is_enabled, 1)
      ");
      $pdo->exec("
        CREATE TRIGGER trg_users_sync_enabled_update BEFORE UPDATE ON users
        FOR EACH ROW SET NEW.is_enabled = COALESCE(NEW.is_enabled, NEW.enabled, OLD.is_enabled),
                        NEW.enabled    = COALESCE(NEW.enabled, NEW.is_enabled, OLD.enabled)
      ");
    } catch (Throwable $e) { /* 忽略 */ }
  }
}

function create_boss_if_none(PDO $pdo, string $username, string $password, string $displayName): void {
  $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
  if ($cnt>0) return;
  $hash = password_hash($password, PASSWORD_DEFAULT);
  $st = $pdo->prepare("INSERT INTO users(username, display_name, role, password_hash, enabled, is_enabled) VALUES(?,?,?,?,1,1)");
  $st->execute([$username, $displayName, 'boss', $hash]);
}

/** 安全删除：先尝试 unlink，失败则 rename 为 .deleted.ts，再失败就清空并 chmod 0000 */
function safe_delete(string $file): array {
  $res = ['file'=>$file, 'deleted'=>false, 'renamed'=>false, 'wiped'=>false, 'error'=>null];
  if (!file_exists($file)) { $res['deleted']=true; return $res; }
  if (is_dir($file)) { $res['error']='目标是目录，未删除'; return $res; }
  if (@unlink($file)) { $res['deleted']=true; return $res; }
  @chmod($file, 0640);
  if (@unlink($file)) { $res['deleted']=true; return $res; }
  $new = $file . '.deleted.' . time();
  if (@rename($file, $new)) { $res['renamed']=true; return $res; }
  if (@file_put_contents($file, '', LOCK_EX)!==false) { @chmod($file, 0000); $res['wiped']=true; return $res; }
  $res['error']='无法删除/重命名/清空（可能是权限或只读文件系统）';
  return $res;
}

if (file_exists($lockFile)) {
  http_response_code(403);
  echo '<meta charset="utf-8"><div style="max-width:720px;margin:8vh auto;font:16px/1.65 system-ui">
    <h2>安装已完成</h2>
    <p>检测到 <code>install/install.lock</code>。如需重新安装，请删除该文件（不建议线上）。</p>
    <p><a href="/index.php">前往首页 &raquo;</a></p></div>';
  exit;
}

$step = (int)($_GET['step'] ?? 1);

// STEP 1：环境检查
if ($step===1) {
  $checks = [
    'PHP ≥ 8.1'                 => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO + MySQL 扩展'           => extension_loaded('pdo_mysql'),
    '根目录可写 (生成 db.php)'   => is_writable($root),
    '模板可读'                  => is_readable($templateFile),
    'SQL 可读 (erp.sql)'        => is_readable($schemaFile),
  ];
  $ok = !in_array(false, $checks, true);
  echo '<meta charset="utf-8"><div style="max-width:720px;margin:8vh auto;font:16px/1.7 system-ui">';
  echo '<h2>ERP 安装向导 · 环境检查</h2><ul>';
  foreach ($checks as $k=>$v) echo '<li>'.h($k).'：'.($v?'✅ 通过':'❌ 未通过').'</li>';
  echo '</ul>';
  echo $ok ? '<p><a href="?step=2">继续 &raquo;</a></p>' : '<p style="color:#c00">请修复未通过项后刷新。</p>';
  echo '</div>'; exit;
}

// STEP 2：填写数据库 + 管理员信息
if ($step===2 && $_SERVER['REQUEST_METHOD']!=='POST') {
  $csrf = csrf_token();
  echo '<meta charset="utf-8"><div style="max-width:720px;margin:8vh auto;font:16px/1.7 system-ui">';
  echo '<h2>填写数据库与管理员信息</h2>';
  echo '<form method="post"><input type="hidden" name="csrf" value="'.h($csrf).'">';
  echo '<label>主机</label><br><input name="host" value="localhost" required style="width:100%;padding:.6rem"><br><br>';
  echo '<label>端口</label><br><input name="port" value="3306" required style="width:100%;padding:.6rem"><br><br>';
  echo '<label>数据库名</label><br><input name="name" required style="width:100%;padding:.6rem"><br><br>';
  echo '<label>用户名</label><br><input name="user" required style="width:100%;padding:.6rem"><br><br>';
  echo '<label>密码</label><br><input type="password" name="pass" required style="width:100%;padding:.6rem"><br><br>';
  echo '<fieldset style="border:1px solid #ddd;padding:1rem;border-radius:.5rem">';
  echo '<legend>管理员（首次安装会创建）</legend>';
  echo '<label>管理员用户名</label><br><input name="boss_user" value="admin" required style="width:100%;padding:.6rem"><br><br>';
  echo '<label>管理员密码</label><br><input type="password" name="boss_pass" required style="width:100%;padding:.6rem"><br><br>';
  echo '<label>显示名称</label><br><input name="boss_name" value="老板" required style="width:100%;padding:.6rem">';
  echo '</fieldset><br>';
  echo '<button style="padding:.7rem 1.4rem">开始安装</button></form></div>'; exit;
}

// STEP 2 提交：执行安装
if ($step===2 && $_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $host = trim($_POST['host'] ?? '');
  $port = trim($_POST['port'] ?? '3306');
  $name = trim($_POST['name'] ?? '');
  $user = trim($_POST['user'] ?? '');
  $pass = (string)($_POST['pass'] ?? '');
  $boss_user = trim($_POST['boss_user'] ?? 'admin');
  $boss_pass = (string)($_POST['boss_pass'] ?? '');
  $boss_name = trim($_POST['boss_name'] ?? '老板');

  try {
    if ($host===''||$port===''||$name===''||$user===''||$boss_user===''||$boss_pass==='') {
      throw new InvalidArgumentException('请填写完整信息');
    }
    $pdo = pdo_connect($host,$port,$name,$user,$pass);

    // 1) 写 db.php
    write_db_php($templateFile, $targetDbPhp, compact('host','port','name','user','pass'));

    // 2) 导入 schema（自动处理 DELIMITER & 视图占位 & 清理 DEFINER）
    import_sql_with_delimiters($pdo, $schemaFile, true);

    // 3) 兼容 users.enabled → is_enabled
    ensure_enabled_alias($pdo);

    // 4) 无用户则创建管理员
    create_boss_if_none($pdo, $boss_user, $boss_pass, $boss_name);

    // 5) 写锁
    file_put_contents($lockFile, "installed at ".date('c')."\n", LOCK_EX);
    @chmod($lockFile, 0640);

    // 6) 安装完成后尝试删除安装文件
    $toDelete = [
      __FILE__,                     // /install/install.php（当前脚本）
      __DIR__ . '/erp.sql',         // SQL
      __DIR__ . '/template.db.php', // 模板
    ];
    $results = [];
    foreach ($toDelete as $f) { $results[] = safe_delete($f); }

    // 完成页
    echo '<meta charset="utf-8"><div style="max-width:720px;margin:8vh auto;font:16px/1.7 system-ui">';
    echo '<h2>安装完成 ✅</h2>';
    echo '<p>系统已导入数据结构、写入 <code>db.php</code>，并创建管理员：<code>'.h($boss_user).'</code></p>';
    echo '<h3 style="margin-top:1rem;font-size:1.1rem">安装文件清理结果</h3>';
    echo '<ul>';
    foreach ($results as $r) {
      $f = str_replace($root, '', $r['file']);
      if ($r['deleted']) {
        echo '<li><code>'.h($f).'</code> 已删除</li>';
      } elseif ($r['renamed']) {
        echo '<li><code>'.h($f).'</code> 无法删除，已重命名为 <code>'.h(basename($r['file']).'.deleted.*').'</code></li>';
      } elseif ($r['wiped']) {
        echo '<li><code>'.h($f).'</code> 无法删除/重命名，已清空并设置 0000 权限</li>';
      } else {
        echo '<li><code>'.h($f).'</code> 清理失败：'.h((string)$r['error']).'</li>';
      }
    }
    echo '</ul>';
    echo '<p style="color:#c00">安全建议：如上若有未能直接删除的文件，请手动移除 <code>/install</code> 目录。</p>';
    echo '<p><a href="/index.php">前往首页 &raquo;</a></p></div>'; exit;

  } catch (Throwable $e) {
    $msg = nl2br(h($e->getMessage()));
    echo '<meta charset="utf-8"><div style="max-width:720px;margin:8vh auto;font:16px/1.7 system-ui">';
    echo '<h2>安装失败</h2><p style="color:#c00;white-space:pre-wrap">'.$msg.'</p>';
    echo '<p><a href="?step=2">返回重试</a></p></div>'; exit;
  }
}

// 兜底跳转
header('Location: ?step=1'); exit;
