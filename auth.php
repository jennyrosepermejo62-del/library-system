<?php
// auth.php
// FIXED: top-level code guarded by basename() so require_once from api.php is safe.
// FIXED: reads 'identifier' field (username OR email).
// FIXED: returns member_id + name in token response.

require_once 'db.php';

// ── Only run as entry-point when called directly ──────────────────────────────
if (basename($_SERVER['SCRIPT_FILENAME']) === 'auth.php') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

    $pdo    = getDBConnection();
    $action = $_GET['action'] ?? '';

    // Passive session cleanup
    try { $pdo->exec("DELETE FROM sessions WHERE expires_at < NOW()"); } catch (Throwable $e) {}

    switch ($action) {
        case 'login':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['success'=>false,'message'=>'POST required'], 405);
            handleLogin($pdo);
            break;

        case 'logout':
            handleLogout($pdo);
            break;

        case 'me':
            $user = requireAuth($pdo);
            $stmt = $pdo->prepare("SELECT u.id,u.username,u.email,u.role,u.member_id,m.name,m.membership_type FROM users u LEFT JOIN members m ON u.member_id=m.id WHERE u.id=?");
            $stmt->execute([$user['id']]);
            jsonResponse(['success'=>true,'data'=>$stmt->fetch()]);
            break;

        default:
            jsonResponse(['success'=>false,'message'=>'Unknown action'], 404);
    }
}

// ── Login handler ─────────────────────────────────────────────────────────────
function handleLogin(PDO $pdo): void {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    // Accept 'identifier' (what JS sends) or fallback 'username'
    $identifier = trim($body['identifier'] ?? $body['username'] ?? '');
    $password   = $body['password'] ?? '';

    if (!$identifier || !$password) {
        jsonResponse(['success'=>false,'message'=>'Username/email and password are required'], 400);
    }

    // Query by username OR email
    $stmt = $pdo->prepare("
        SELECT u.*, m.name AS member_name, m.membership_type, m.status AS member_status
        FROM users u
        LEFT JOIN members m ON u.member_id = m.id
        WHERE u.username = ? OR u.email = ?
        LIMIT 1
    ");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['success'=>false,'message'=>'Invalid credentials'], 401);
    }

    if (!password_verify($password, $user['password_hash'])) {
        jsonResponse(['success'=>false,'message'=>'Invalid credentials'], 401);
    }

    if ($user['role'] === 'member' && ($user['member_status'] ?? '') === 'suspended') {
        jsonResponse(['success'=>false,'message'=>'Account suspended. Contact the library.'], 403);
    }

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + 86400);

    $pdo->prepare("INSERT INTO sessions (user_id, token_hash, expires_at) VALUES (?,?,?)")
        ->execute([$user['id'], $tokenHash, $expiresAt]);
    $pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")
        ->execute([$user['id']]);

    jsonResponse([
        'success'    => true,
        'token'      => $rawToken,
        'expires_at' => $expiresAt,
        'user'       => [
            'id'              => (int)$user['id'],
            'username'        => $user['username'],
            'email'           => $user['email'],
            'role'            => $user['role'],
            'member_id'       => $user['member_id'] ? (int)$user['member_id'] : null,
            'name'            => $user['member_name'] ?? $user['username'],
            'membership_type' => $user['membership_type'] ?? null,
        ],
    ]);
}

function handleLogout(PDO $pdo): void {
    $token = getTokenFromRequest();
    if ($token) {
        $pdo->prepare("DELETE FROM sessions WHERE token_hash=?")
            ->execute([hash('sha256', $token)]);
    }
    jsonResponse(['success'=>true,'message'=>'Logged out']);
}

// ── Shared helpers — safe to define here, api.php uses them via require_once ──
if (!function_exists('getTokenFromRequest')) {
    function getTokenFromRequest(): ?string {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
        if (strpos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
        return $auth ?: null;
    }
}

if (!function_exists('requireAuth')) {
    function requireAuth(PDO $pdo, ?string $requiredRole = null): array {
        $token = getTokenFromRequest();
        if (!$token) {
            jsonResponse(['success'=>false,'message'=>'Authentication required','code'=>'NO_TOKEN'], 401);
        }
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.email, u.role, u.member_id
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.token_hash = ? AND s.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$hash]);
        $user = $stmt->fetch();
        if (!$user) {
            jsonResponse(['success'=>false,'message'=>'Session expired. Please log in again.','code'=>'INVALID_TOKEN'], 401);
        }
        if ($requiredRole && $user['role'] !== $requiredRole) {
            jsonResponse(['success'=>false,'message'=>'Access denied.','code'=>'FORBIDDEN'], 403);
        }
        return $user;
    }
}
