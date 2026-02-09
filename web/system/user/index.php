<?php

/**
 * Maiburogu Backend API
 * 处理文章列表、分类获取及搜索
 */
require_once '../config/database.php';
header('Content-Type: application/json');

try {
    $pdo = getDatabaseConnection();

    // 获取参数
    $action = $_GET['action'] ?? 'get_posts';

    if ($action === 'get_categories') {
        $stmt = $pdo->query("SELECT * FROM categories ORDER BY id ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    if ($action === 'get_posts') {
        $search = $_GET['search'] ?? '';
        $sort = $_GET['sort'] ?? 'latest';
        $category_id = $_GET['category'] ?? null;

        // 构建排序
        $orderBy = "p.published_at DESC";
        if ($sort === 'views') {
            $orderBy = "p.view_count DESC";
        } elseif ($sort === 'likes') {
            $orderBy = "p.like_count DESC";
        }

        $sql = "SELECT p.*, c.name as category_name 
                FROM posts p 
                LEFT JOIN categories c ON p.category_id = c.id 
                WHERE p.status = 1";

        $params = [];
        if (!empty($search)) {
            $sql .= " AND p.title LIKE ?";
            $params[] = "%$search%";
        }
        if ($category_id) {
            $sql .= " AND p.category_id = ?";
            $params[] = $category_id;
        }

        $sql .= " ORDER BY $orderBy LIMIT 20";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    throw new Exception("Invalid Action");
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
