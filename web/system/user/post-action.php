<?php

/**
 * Maiburogu - Interaction Handler API
 * 功能：处理点赞（防刷）与提交评论（待审核状态、频率限制）
 */
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// 仅允许 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '无效的请求方法']);
    exit;
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$postId = isset($_POST['post_id']) ? (int)$_POST['post_id'] : 0;
$ip = $_SERVER['REMOTE_ADDR'];

if ($postId <= 0) {
    echo json_encode(['success' => false, 'message' => '无效的文章 ID']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    if ($action === 'like') {
        // --- 点赞逻辑 ---

        // 1. 检查 1 小时内是否已点赞
        $checkLike = $pdo->prepare("
            SELECT id FROM interaction_logs 
            WHERE post_id = ? AND ip_address = ? AND type = 'like' 
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) 
            LIMIT 1
        ");
        $checkLike->execute([$postId, $ip]);

        if ($checkLike->fetch()) {
            echo json_encode(['success' => false, 'message' => '您最近已经点过赞了，请稍后再试']);
            exit;
        }

        // 2. 更新 posts 表的点赞数
        $pdo->prepare("UPDATE posts SET like_count = like_count + 1 WHERE id = ?")->execute([$postId]);

        // 3. 记录点赞日志
        $pdo->prepare("INSERT INTO interaction_logs (post_id, ip_address, type) VALUES (?, ?, 'like')")
            ->execute([$postId, $ip]);

        echo json_encode(['success' => true, 'message' => '点赞成功！']);
    } elseif ($action === 'comment') {
        // --- 提交评论逻辑 ---

        $nickname = isset($_POST['nickname']) ? trim($_POST['nickname']) : '';
        $content = isset($_POST['content']) ? trim($_POST['content']) : '';

        if (empty($nickname) || empty($content)) {
            echo json_encode(['success' => false, 'message' => '暱称与内容不能为空']);
            exit;
        }

        // 1. 频率限制：检查该 IP 在 1 小时内对该帖子的评论总数
        $checkLimit = $pdo->prepare("
            SELECT COUNT(*) as comment_count 
            FROM comments 
            WHERE post_id = ? AND ip_address = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $checkLimit->execute([$postId, $ip]);
        $row = $checkLimit->fetch();

        if ($row && $row['comment_count'] >= 10) {
            echo json_encode(['success' => false, 'message' => '您的评论过于频繁，每小时对单篇文章最多发表 10 条评论']);
            exit;
        }

        // 2. 写入 comments 表
        // 注意：根据 maiburogu.sql，is_audited 预设为 0 (待审核)
        $stmt = $pdo->prepare("
            INSERT INTO comments (post_id, nickname, content, ip_address, is_audited, created_at) 
            VALUES (?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$postId, $nickname, $content, $ip]);

        echo json_encode(['success' => true, 'message' => '评论已提交，请等待管理员审核']);
    } else {
        echo json_encode(['success' => false, 'message' => '未知的操作类型']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '资料库错误: ' . $e->getMessage()]);
}
