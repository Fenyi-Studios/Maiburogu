<?php

/**
 * Category Posts API - 获取特定分类下的文章列表
 */
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$categoryId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($categoryId <= 0) {
    echo json_encode(['success' => false, 'message' => '无效的分类 ID']);
    exit;
}

$pdo = getDatabaseConnection();

try {
    // 1. 获取分类资讯
    $stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch();

    if (!$category) {
        echo json_encode(['success' => false, 'message' => '分类不存在']);
        exit;
    }

    // 2. 获取该分类下的文章列表 (仅获取已发布的文章)
    $sql = "SELECT id, title, summary, tags, published_at 
            FROM posts 
            WHERE category_id = ? AND status = 1 
            ORDER BY published_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$categoryId]);
    $posts = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => [
            'category_name' => $category['name'],
            'posts' => $posts
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '服务器内部错误']);
}
