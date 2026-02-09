<?php

/**
 * Github Repo. RuizeSun/Maiburogu
 * Database Connection Configuration
 */
const DB_HOST = 'localhost'; // Database Hostname
const DB_NAME = 'maiburogu'; // Database Name
const DB_USER = 'maiburogu'; // Database Username
const DB_PASS = 'maiburogu'; // Database Password

function getDatabaseConnection()
{
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['success' => false, 'message' => 'Failed to connect to the database.']);
        exit;
    }
}
