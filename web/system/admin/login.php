<?php

/**
 * Github Repo. RuizeSun/Maiburogu
 * Admin Login API (JSON Backend) - Enhanced with Rate Limiting
 */

header('Content-Type: application/json');
session_start();
require_once '../config/database.php';

// 设定限制参数
define('MAX_ATTEMPTS', 5);        // 最大尝试次数
define('LOCKOUT_TIME', 300);      // 锁定时间（秒），5 分钟

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '无效的请求方法。']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? '';
$password = $input['password'] ?? '';
$ip_address = $_SERVER['REMOTE_ADDR'];

if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => '请输入用户名或密码。']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    // 1. 检查是否处于锁定状态 (针对 IP 或用户名)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_attempts 
        WHERE (username = ? OR ip_address = ?) 
        AND attempt_time > (NOW() - INTERVAL " . LOCKOUT_TIME . " SECOND)
    ");
    $stmt->execute([$username, $ip_address]);
    $attempts = $stmt->fetchColumn();

    if ($attempts >= MAX_ATTEMPTS) {
        echo json_encode([
            'success' => false,
            'message' => '尝试次数过多，请在 5 分钟后再试。'
        ]);
        exit;
    }

    // 2. 查询管理员资讯
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password'])) {
        // 登录成功：
        // 预防 Session 固定攻击
        session_regenerate_id(true);

        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_user'] = $admin['username'];
        $_SESSION['admin_name'] = $admin['nickname'];

        // 更新最后登录时间
        $updateStmt = $pdo->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = ?");
        $updateStmt->execute([$admin['id']]);

        // 登录成功后，清除该用户/IP 的失败尝试记录（可选）
        $clearStmt = $pdo->prepare("DELETE FROM login_attempts WHERE username = ? OR ip_address = ?");
        $clearStmt->execute([$username, $ip_address]);

        echo json_encode([
            'success' => true,
            'message' => '登录成功'
        ]);
    } else {
        // 登录失败：记录此次尝试
        $logStmt = $pdo->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)");
        $logStmt->execute([$username, $ip_address]);

        $remaining = MAX_ATTEMPTS - ($attempts + 1);
        $msg = $remaining > 0 ? "用户名或密码错误。剩余尝试次数：$remaining" : "尝试次数过多，帐号已锁定。";

        echo json_encode(['success' => false, 'message' => $msg]);
    }
} catch (PDOException $e) {
    // 应避免将具体的报错抛给前端
    echo json_encode(['success' => false, 'message' => '伺服器内部错误，请稍后再试。']);
}
