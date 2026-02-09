-- 1. 管理员表 (仅保留结构)
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '登录账号',
  `password` varchar(255) NOT NULL COMMENT '加密后的密码',
  `nickname` varchar(50) DEFAULT NULL COMMENT '显示名称',
  `last_login_at` datetime DEFAULT NULL COMMENT '最后登录时间',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. 分类表 (保留默认分类)
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '分类名称',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `categories` (`id`, `name`) VALUES (1, '默认分类');

-- 3. 文章主表
DROP TABLE IF EXISTS `posts`;
CREATE TABLE `posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL COMMENT '标题',
  `summary` varchar(500) DEFAULT NULL COMMENT '摘要/简介',
  `category_id` int(10) unsigned DEFAULT '1' COMMENT '分类ID',
  `tags` varchar(255) DEFAULT '' COMMENT '标签，逗号分隔',
  `view_count` int(10) unsigned DEFAULT '0' COMMENT '阅读量',
  `like_count` int(10) unsigned DEFAULT '0' COMMENT '点赞数',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态: 0-草稿, 1-已发布',
  `published_at` datetime NOT NULL COMMENT '发布时间',
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT '修改时间',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_published_at` (`published_at`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. 文章内容表
DROP TABLE IF EXISTS `post_contents`;
CREATE TABLE `post_contents` (
  `post_id` int(10) unsigned NOT NULL,
  `content` longtext NOT NULL COMMENT '文章主体内容',
  `content_html` longtext NOT NULL COMMENT '解析后的HTML内容',
  PRIMARY KEY (`post_id`),
  CONSTRAINT `post_contents_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. 多语言翻译表
DROP TABLE IF EXISTS `post_translations`;
CREATE TABLE `post_translations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `lang` varchar(10) NOT NULL COMMENT '语言代码: en-US, ja-JP 等',
  `title` varchar(255) NOT NULL,
  `content` longtext NOT NULL,
  `content_html` longtext NOT NULL,
  `translated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_post_lang` (`post_id`,`lang`),
  CONSTRAINT `post_trans_fk` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. 评论表
DROP TABLE IF EXISTS `comments`;
CREATE TABLE `comments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL COMMENT '关联文章ID',
  `nickname` varchar(50) NOT NULL COMMENT '评论者昵称',
  `content` text NOT NULL COMMENT '评论内容',
  `ip_address` varchar(45) NOT NULL COMMENT '评论者IP(支持IPv6)',
  `is_audited` tinyint(1) DEFAULT '0' COMMENT '审核状态: 0-待审核, 1-已通过',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '评论时间',
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_audit` (`is_audited`),
  CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. 交互日志表 (阅读/点赞记录)
DROP TABLE IF EXISTS `interaction_logs`;
CREATE TABLE `interaction_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(10) unsigned NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `type` enum('view','like') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_post_type` (`ip_address`,`post_id`,`type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. 登录尝试记录表 (安全防御)
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempt_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. 系统设置表 (仅保留翻译语言配置)
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `key_name` varchar(50) NOT NULL,
  `value` text,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`key_name`, `value`) VALUES ('target_langs', '["en-US","ja-JP","ko-KR"]');