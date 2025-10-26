<?php
// logout.php
declare(strict_types=1);

// 启动 Session
session_start();

// 清空所有 Session 数据
$_SESSION = [];

// 如果存在 Session Cookie，也清理掉
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 销毁 Session
session_destroy();

// 跳转到登录页面（如果你登录页路径不同请改成相应路径）
header("Location: /login.php");
exit;
