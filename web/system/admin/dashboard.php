<?php

/**
 * Dashboard API
 * 处理后台所有数据交互 (JSON 格式)
 * 新增：分类管理、设置管理、AI翻译
 */

header('Content-Type: application/json');
session_start();
require_once '../config/database.php';

// 权限验证
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登录或会话已过期']);
    exit;
}

$pdo = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function getJsonInput()
{
    return json_decode(file_get_contents('php://input'), true) ?? [];
}


try {
    switch ($action) {
        // --- 统计数据 ---
        case 'get_stats':
            $postCount = $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
            $pendingComments = $pdo->query("SELECT COUNT(*) FROM comments WHERE is_audited = 0")->fetchColumn();
            $totalLikes = $pdo->query("SELECT SUM(like_count) FROM posts")->fetchColumn() ?: 0;
            $catCount = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();

            echo json_encode([
                'success' => true,
                'data' => [
                    'posts' => $postCount,
                    'pending_comments' => $pendingComments,
                    'likes' => $totalLikes,
                    'categories' => $catCount
                ]
            ]);
            break;

        // --- 系统设置 (AI配置) ---
        case 'get_settings':
            $stmt = $pdo->query("SELECT * FROM settings");
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['key_name']] = $row['value'];
            }
            // 默认值
            $data = [
                'openrouter_api_key' => $settings['openrouter_api_key'] ?? '',
                'ai_model' => $settings['ai_model'] ?? 'google/gemini-2.0-flash-001', // 默认模型
                'target_langs' => isset($settings['target_langs']) ? json_decode($settings['target_langs'], true) : ['en-US', 'ja-JP']
            ];
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        case 'save_settings':
            if ($method !== 'POST') throw new Exception('无效的请求方法');
            $input = getJsonInput();

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("REPLACE INTO settings (key_name, value) VALUES (?, ?)");
            $stmt->execute(['openrouter_api_key', $input['openrouter_api_key'] ?? '']);
            $stmt->execute(['ai_model', $input['ai_model'] ?? '']);
            $stmt->execute(['target_langs', json_encode($input['target_langs'] ?? [])]);
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => '设置已保存']);
            break;

        // --- 分类管理 ---
        case 'get_categories':
            // 获取分类及该分类下的文章数
            $stmt = $pdo->query("
                SELECT c.*, COUNT(p.id) as post_count 
                FROM categories c 
                LEFT JOIN posts p ON c.id = p.category_id 
                GROUP BY c.id 
                ORDER BY c.id ASC
            ");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

        case 'save_category':
            if ($method !== 'POST') throw new Exception('无效的请求方法');
            $input = getJsonInput();
            $name = trim($input['name']);
            if (empty($name)) throw new Exception("分类名不能为空");

            if (!empty($input['id'])) {
                $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
                $stmt->execute([$name, $input['id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
                $stmt->execute([$name]);
            }
            echo json_encode(['success' => true, 'message' => '分类已保存']);
            break;

        case 'delete_category':
            if ($method !== 'POST') throw new Exception('无效的请求方法');
            $input = getJsonInput();
            $id = $input['id'];
            $mergeToId = $input['merge_to_id']; // 如果该分类下有文章，需要合并到另一个分类

            if ($id == $mergeToId) throw new Exception("不能合并到自身");

            $pdo->beginTransaction();
            // 1. 将原分类文章移动到新分类
            $stmt = $pdo->prepare("UPDATE posts SET category_id = ? WHERE category_id = ?");
            $stmt->execute([$mergeToId, $id]);
            // 2. 删除分类
            $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => '分类已删除并合并']);
            break;

        // --- 文章管理 (更新版) ---
        case 'get_posts':
            // 联表查询获取分类名称
            $stmt = $pdo->query("
                SELECT p.id, p.title, p.status, p.published_at, p.view_count, p.like_count, p.tags, c.name as category_name 
                FROM posts p 
                LEFT JOIN categories c ON p.category_id = c.id
                ORDER BY p.published_at DESC LIMIT 50
            ");
            $posts = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $posts]);
            break;

        case 'get_post_detail':
            $id = $_GET['id'] ?? 0;
            $stmt = $pdo->prepare("
                SELECT p.*, pc.content, pc.content_html 
                FROM posts p 
                LEFT JOIN post_contents pc ON p.id = pc.post_id 
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $post = $stmt->fetch();

            // 获取已有的翻译版本
            if ($post) {
                $stmtTrans = $pdo->prepare("SELECT lang, title FROM post_translations WHERE post_id = ?");
                $stmtTrans->execute([$id]);
                $post['translations'] = $stmtTrans->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($post) {
                echo json_encode(['success' => true, 'data' => $post]);
            } else {
                echo json_encode(['success' => false, 'message' => '文章不存在']);
            }
            break;

        case 'save_post':
            if ($method !== 'POST') throw new Exception('无效的请求方法');
            $input = getJsonInput();

            $id = $input['id'] ?? null;
            $title = $input['title'];
            $summary = $input['summary'];
            $content = $input['content'];
            $status = isset($input['status']) ? intval($input['status']) : 0;
            $categoryId = intval($input['category_id']);
            $tags = trim($input['tags']); // 逗号分隔字符串
            $contentHtml = htmlspecialchars($content); // 简易转码，实际建议用 Parsedown

            $pdo->beginTransaction();

            if ($id) {
                $stmt = $pdo->prepare("UPDATE posts SET title=?, summary=?, status=?, category_id=?, tags=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$title, $summary, $status, $categoryId, $tags, $id]);

                $stmtContent = $pdo->prepare("UPDATE post_contents SET content=?, content_html=? WHERE post_id=?");
                $stmtContent->execute([$content, $contentHtml, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO posts (title, summary, status, category_id, tags, published_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$title, $summary, $status, $categoryId, $tags]);
                $newId = $pdo->lastInsertId();

                $stmtContent = $pdo->prepare("INSERT INTO post_contents (post_id, content, content_html) VALUES (?, ?, ?)");
                $stmtContent->execute([$newId, $content, $contentHtml]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => '操作成功']);
            break;

        // --- AI 翻译功能 ---
        case 'ai_translate':
            if ($method !== 'POST') throw new Exception('无效的请求方法');
            $input = getJsonInput();
            $postId = $input['post_id'];

            // 1. 获取文章内容
            $stmt = $pdo->prepare("SELECT p.title, p.summary, pc.content FROM posts p JOIN post_contents pc ON p.id = pc.post_id WHERE p.id = ?");
            $stmt->execute([$postId]);
            $post = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$post) throw new Exception("文章不存在");

            // 2. 获取配置
            $stmtSet = $pdo->query("SELECT * FROM settings");
            $settings = [];
            while ($row = $stmtSet->fetch(PDO::FETCH_ASSOC)) $settings[$row['key_name']] = $row['value'];

            $apiKey = $settings['openrouter_api_key'] ?? '';
            $model = $settings['ai_model'] ?? 'google/gemini-2.0-flash-001';
            $targetLangs = json_decode($settings['target_langs'] ?? '[]', true);

            if (empty($apiKey)) throw new Exception("请先在设置中配置 OpenRouter API Key");
            if (empty($targetLangs)) throw new Exception("请选择至少一种目标语言");

            // 3. 构建 Prompt
            $langsStr = implode(", ", $targetLangs);
            $prompt = "You are a professional blog translator. Translate the following blog post into these languages: {$langsStr}.\n\n";
            $prompt .= "Return STRICT JSON format only, like this:\n";
            $prompt .= "{\n";
            foreach ($targetLangs as $lang) {
                $prompt .= "  \"{$lang}\": { \"title\": \"Translated Title\", \"content\": \"Translated Markdown Content...\" },\n";
            }
            $prompt .= "}\n\n";
            $prompt .= "Original Title: " . $post['title'] . "\n";
            $prompt .= "Original Content:\n" . $post['content'];

            // 4. 调用 OpenRouter
            $ch = curl_init("https://openrouter.ai/api/v1/chat/completions");
            $payload = json_encode([
                "model" => $model,
                "messages" => [
                    ["role" => "system", "content" => "You are a helpful assistant that outputs only valid JSON."],
                    ["role" => "user", "content" => $prompt]
                ]
            ]);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $apiKey,
                "Content-Type: application/json",
                "HTTP-Referer: http://localhost", // OpenRouter 要求
            ]);

            $response = curl_exec($ch);
            if (curl_errno($ch)) throw new Exception('OpenRouter 请求失败: ' . curl_error($ch));
            curl_close($ch);

            $result = json_decode($response, true);
            if (isset($result['error'])) throw new Exception('AI API 错误: ' . json_encode($result['error']));

            // 5. 解析并保存
            $aiContent = $result['choices'][0]['message']['content'] ?? '';
            // 尝试提取 JSON (去掉可能的 markdown 代码块标记)
            $aiContent = preg_replace('/^```json|```$/m', '', $aiContent);
            $translations = json_decode($aiContent, true);

            if (!$translations) throw new Exception("AI 返回格式解析失败，请重试");

            $pdo->beginTransaction();
            foreach ($translations as $lang => $data) {
                if (in_array($lang, $targetLangs)) {
                    $transTitle = $data['title'];
                    $transContent = $data['content'];
                    $transHtml = htmlspecialchars($transContent); // 同样，实际建议用 Parsedown

                    $stmt = $pdo->prepare("
                        INSERT INTO post_translations (post_id, lang, title, content, content_html) 
                        VALUES (?, ?, ?, ?, ?) 
                        ON DUPLICATE KEY UPDATE title=?, content=?, content_html=?, translated_at=NOW()
                    ");
                    $stmt->execute([$postId, $lang, $transTitle, $transContent, $transHtml, $transTitle, $transContent, $transHtml]);
                }
            }
            $pdo->commit();

            echo json_encode(['success' => true, 'message' => '翻译完成并已保存']);
            break;

        // ... 其他原有 case 保持不变 ...
        case 'delete_post':
            if ($method !== 'POST') throw new Exception('无效的请求方法');
            $input = getJsonInput();
            $id = $input['id'];
            $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => '文章已删除']);
            break;
        case 'toggle_status':
            if ($method !== 'POST') throw new Exception('无效的请求方法');
            $input = getJsonInput();
            $id = $input['id'];
            $status = $input['status'];
            $stmt = $pdo->prepare("UPDATE posts SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            echo json_encode(['success' => true, 'message' => '状态已更新']);
            break;
        case 'upload_file':
            if ($method !== 'POST') throw new Exception('无效的请求方法');
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('文件上传失败');
            }
            $file = $_FILES['file'];
            $type = $_POST['type'] ?? 'attachment';
            $uploadDir = '../../files/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $newFilename = uniqid() . '.' . $ext;
            $targetPath = $uploadDir . $newFilename;
            $publicUrl = "/files/" . $newFilename;

            if ($type === 'image') {
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                if (!in_array($ext, $allowed)) throw new Exception('不支持的图片格式');
                compressAndResizeImage($file['tmp_name'], $targetPath, 720, 75);
            } elseif ($type === 'video') {
                $allowed = ['mp4', 'webm', 'ogg'];
                if (!in_array($ext, $allowed)) throw new Exception('不支持的视频格式');
                if ($file['size'] > 50 * 1024 * 1024) throw new Exception('视频文件不能超过 50MB');
                move_uploaded_file($file['tmp_name'], $targetPath);
            } else {
                if ($file['size'] > 10 * 1024 * 1024) throw new Exception('附件不能超过 10MB');
                move_uploaded_file($file['tmp_name'], $targetPath);
            }

            echo json_encode(['success' => true, 'message' => '上传成功', 'url' => $publicUrl, 'filename' => $file['name']]);
            break;
        case 'get_comments':
            $stmt = $pdo->query("SELECT c.*, p.title as post_title FROM comments c LEFT JOIN posts p ON c.post_id = p.id ORDER BY c.created_at DESC LIMIT 50");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;
        case 'audit_comment':
            if ($method !== 'POST') throw new Exception('无效的请求方法');
            $input = getJsonInput();
            $stmt = $pdo->prepare("UPDATE comments SET is_audited = 1 WHERE id = ?");
            $stmt->execute([$input['id']]);
            echo json_encode(['success' => true, 'message' => '审核通过']);
            break;
        case 'delete_comment':
            if ($method !== 'POST') throw new Exception('无效的请求方法');
            $input = getJsonInput();
            $stmt = $pdo->prepare("DELETE FROM comments WHERE id = ?");
            $stmt->execute([$input['id']]);
            echo json_encode(['success' => true, 'message' => '删除成功']);
            break;
        case 'logout':
            session_destroy();
            echo json_encode(['success' => true]);
            break;
        case 'check_login':
            echo json_encode(['success' => true, 'user' => $_SESSION['admin_name']]);
            break;
        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// 辅助函数保持不变
function compressAndResizeImage($source, $destination, $minShortEdge, $quality)
{
    $info = getimagesize($source);
    if (!$info) throw new Exception('无效的图片文件');
    $mime = $info['mime'];
    $width = $info[0];
    $height = $info[1];
    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($source);
            break;
        default:
            throw new Exception('不支持的图片类型');
    }
    $shortEdge = min($width, $height);
    $ratio = 1;
    if ($shortEdge > $minShortEdge) {
        $ratio = $minShortEdge / $shortEdge;
        $newWidth = intval($width * $ratio);
        $newHeight = intval($height * $ratio);
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        if ($mime == 'image/png' || $mime == 'image/webp') {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
        }
        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $image = $newImage;
    }
    if ($mime == 'image/jpeg' || $mime == 'image/jpg') {
        imagejpeg($image, $destination, $quality);
    } elseif ($mime == 'image/png') {
        imagepng($image, $destination, 8);
    } else {
        imagejpeg($image, $destination, $quality);
    }
    imagedestroy($image);
}
