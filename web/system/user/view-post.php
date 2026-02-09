<?php

/**
 * Maiburogu - View Post API (v2)
 * 修改：在返回数据中加入 category_id 字段
 */
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$requestedLang = isset($_GET['lang']) ? trim($_GET['lang']) : 'zh-CN';
$ip = $_SERVER['REMOTE_ADDR'];

if ($postId <= 0) {
    echo json_encode(['success' => false, 'message' => '无效的文章 ID']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    // 1. 阅读量统计逻辑 (1 小时内同 IP 仅算一次)
    $checkView = $pdo->prepare("
        SELECT id FROM interaction_logs 
        WHERE post_id = ? AND ip_address = ? AND type = 'view' 
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) 
        LIMIT 1
    ");
    $checkView->execute([$postId, $ip]);

    if (!$checkView->fetch()) {
        $pdo->prepare("UPDATE posts SET view_count = view_count + 1 WHERE id = ?")->execute([$postId]);
        $pdo->prepare("INSERT INTO interaction_logs (post_id, ip_address, type) VALUES (?, ?, 'view')")->execute([$postId, $ip]);
    }

    // 2. 获取文章基础资料与分类名称 (修改处：增加 p.category_id)
    $stmt = $pdo->prepare("
        SELECT p.*, c.name as category_name, p.category_id 
        FROM posts p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.id = ? AND p.status = 1
    ");
    $stmt->execute([$postId]);
    $postData = $stmt->fetch();

    if (!$postData) {
        echo json_encode(['success' => false, 'message' => '文章不存在或尚未发布']);
        exit;
    }

    // 3. 获取已审核的评论
    $commentStmt = $pdo->prepare("
        SELECT nickname, content, created_at 
        FROM comments 
        WHERE post_id = ? AND is_audited = 1 
        ORDER BY created_at DESC
    ");
    $commentStmt->execute([$postId]);
    $comments = $commentStmt->fetchAll();

    // 4. 处理多语言内容获取
    $langStmt = $pdo->prepare("SELECT lang FROM post_translations WHERE post_id = ?");
    $langStmt->execute([$postId]);
    $translations = $langStmt->fetchAll(PDO::FETCH_COLUMN);
    $availableLangs = array_unique(array_merge(['zh-CN'], $translations));

    $finalTitle = $postData['title'];
    $finalContentHtml = '';

    if ($requestedLang !== 'zh-CN' && in_array($requestedLang, $translations)) {
        $transStmt = $pdo->prepare("SELECT title, content_html FROM post_translations WHERE post_id = ? AND lang = ?");
        $transStmt->execute([$postId, $requestedLang]);
        $transRes = $transStmt->fetch();
        if ($transRes) {
            $finalTitle = $transRes['title'];
            $finalContentHtml = $transRes['content_html'];
        }
    } else {
        $contentStmt = $pdo->prepare("SELECT content_html FROM post_contents WHERE post_id = ?");
        $contentStmt->execute([$postId]);
        $contentRes = $contentStmt->fetch();
        $finalContentHtml = $contentRes ? $contentRes['content_html'] : '';
    }

    // 5. 回传结果 (修改处：增加 category_id)
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $postId,
            'title' => $finalTitle,
            'content' => $finalContentHtml,
            'category_id' => $postData['category_id'],
            'category_name' => $postData['category_name'] ?: '未分类',
            'tags' => $postData['tags'],
            'view_count' => (int)$postData['view_count'],
            'like_count' => (int)$postData['like_count'],
            'published_at' => $postData['published_at'],
            'availableLangs' => array_values($availableLangs),
            'comments' => $comments
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '资料库错误: ' . $e->getMessage()]);
}
