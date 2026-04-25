<?php
define('DB_HOST',    'localhost');
define('DB_NAME',    'library_db');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

function getDBConnection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            if (!function_exists('jsonResponse')) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success'=>false,'message'=>'DB Error: '.$e->getMessage()]);
                exit;
            }
            jsonResponse(['success'=>false,'message'=>'DB Error: '.$e->getMessage()], 500);
        }
    }
    return $pdo;
}

if (!function_exists('jsonResponse')) {
    function jsonResponse(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('getRequestBody')) {
    function getRequestBody(): array {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }
}
