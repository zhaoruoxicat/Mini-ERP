<?php
// db.php —— 安装器自动生成
declare(strict_types=1);

$DB = [
  'host'    => '{{DB_HOST}}',
  'port'    => '{{DB_PORT}}',
  'name'    => '{{DB_NAME}}',
  'user'    => '{{DB_USER}}',
  'pass'    => '{{DB_PASS}}',
  'charset' => 'utf8mb4',
];

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $DB['host'], $DB['port'], $DB['name'], $DB['charset']);
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
];

try {
  $pdo = new PDO($dsn, $DB['user'], $DB['pass'], $options);
} catch (Throwable $e) {
  http_response_code(500);
  exit('数据库连接失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
