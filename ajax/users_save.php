<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();

header('Content-Type: application/json; charset=utf-8');

// Only ADMIN may create/update users
if (Session::get('user_role') !== 'ADMIN') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

try {
    $pdo = getDB();

    // Collect POST data
    $id = isset($_POST['id']) && is_numeric($_POST['id']) ? (int)$_POST['id'] : 0;
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    // username is no longer collected from form; populate from email to keep legacy column usable
    $username = $email;
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $role = isset($_POST['role']) ? trim($_POST['role']) : '';
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'pending';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
    $kontinjen_id = isset($_POST['kontinjen_id']) && is_numeric($_POST['kontinjen_id']) ? (int)$_POST['kontinjen_id'] : null;
    $password = isset($_POST['password']) ? $_POST['password'] : null;

    // Basic validation (username not required separately; we use email)
    if ($email === '' || $full_name === '' || $role === '') {
        throw new Exception('Email, full name and role are required');
    }

    $allowedRoles = ['ADMIN','ORGANIZER','JUDGE','CONTINGENT','VIEWER'];
    if (!in_array($role, $allowedRoles)) throw new Exception('Invalid role');

    $allowedStatus = ['active','inactive','suspended','pending'];
    if (!in_array($status, $allowedStatus)) throw new Exception('Invalid status');

    // If role is CONTINGENT, kontinjen_id is required
    if ($role === 'CONTINGENT') {
        if ($kontinjen_id === null || $kontinjen_id <= 0) {
            throw new Exception('A contingent is required for the CONTINGENT role');
        }
    } else {
        // for non-contingent roles, clear kontinjen_id to null (not required)
        $kontinjen_id = null;
    }

    // Check uniqueness for username/email
    $params = [':username' => $username];
    $sql = 'SELECT id FROM users WHERE username = :username AND deleted_at IS NULL';
    if ($id > 0) { $sql .= ' AND id != :id'; $params[':id'] = $id; }
    $stmt = $pdo->prepare($sql); $stmt->execute($params); if ($stmt->fetch()) throw new Exception('Username already in use');

    $params = [':email' => $email];
    $sql = 'SELECT id FROM users WHERE email = :email AND deleted_at IS NULL';
    if ($id > 0) { $sql .= ' AND id != :id'; $params[':id'] = $id; }
    $stmt = $pdo->prepare($sql); $stmt->execute($params); if ($stmt->fetch()) throw new Exception('Email already in use');

    $currentUserId = Session::get('user_id');

    if ($id > 0) {
        // Update
        $fields = [
            'username' => $username,
            'email' => $email,
            'full_name' => $full_name,
            'role' => $role,
            'status' => $status,
            'phone' => $phone,
            'kontinjen_id' => $kontinjen_id,
            'updated_by' => $currentUserId
        ];

        $setParts = [];
        $params = [':id' => $id];
        foreach ($fields as $k => $v) { $setParts[] = "$k = :$k"; $params[":$k"] = $v; }

        // Password update only if provided (non-empty)
        if ($password !== null && trim($password) !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $setParts[] = "password_hash = :password_hash";
            $params[':password_hash'] = $hash;
        }

        $sql = 'UPDATE users SET ' . implode(',', $setParts) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'User updated']);
        exit;
    }

    // Create
    if ($password === null || trim($password) === '') throw new Exception('Password required for new user');
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $sql = 'INSERT INTO users (username, email, password_hash, full_name, role, kontinjen_id, status, phone, created_by) VALUES (:username, :email, :password_hash, :full_name, :role, :kontinjen_id, :status, :phone, :created_by)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $hash,
        ':full_name' => $full_name,
        ':role' => $role,
        ':kontinjen_id' => $kontinjen_id,
        ':status' => $status,
        ':phone' => $phone,
        ':created_by' => $currentUserId
    ]);

    $newId = $pdo->lastInsertId();
    echo json_encode(['success' => true, 'message' => 'User created', 'id' => $newId]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
