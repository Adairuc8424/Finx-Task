<?php
// ============================================================
//  auth.php — Autenticación y gestión de perfil
//  Finx-Task Backend
// ============================================================

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Conexión ─────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'finxtask_db');
define('DB_USER', 'root');
define('DB_PASS', '');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']);
            exit;
        }
    }
    return $pdo;
}

// ── Helpers ──────────────────────────────────────────────────
function ok(array $data = []): void {
    echo json_encode(array_merge(['status' => 'success'], $data));
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

function requireAuth(): array {
    // Aceptar token por header o por sesión PHP
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $token = str_replace('Bearer ', '', $token);

    if ($token) {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT u.id, u.nombre, u.email, u.avatar, u.rol
            FROM sesiones s
            JOIN usuarios u ON s.usuario_id = u.id
            WHERE s.token = ? AND s.expira_el > NOW() AND u.activo = 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if ($user) return $user;
    }

    if (!empty($_SESSION['user_id'])) {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, nombre, email, avatar, rol FROM usuarios WHERE id = ? AND activo = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) return $user;
    }

    fail('No autorizado', 401);
}

function generateToken(): string {
    return bin2hex(random_bytes(32));
}

function notificar(int $usuarioId, string $tipo, string $titulo, string $mensaje, string $url = ''): void {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url) VALUES (?,?,?,?,?)");
        $stmt->execute([$usuarioId, $tipo, $titulo, $mensaje, $url]);
    } catch (Exception $e) { /* no bloquear el flujo */ }
}

// ── Enrutador ────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── REGISTRO ─────────────────────────────────────────────
    case 'register':
        $nombre = trim($_POST['name']     ?? '');
        $email  = trim($_POST['email']    ?? '');
        $pass   =      $_POST['password'] ?? '';
        $avatar =      $_POST['avatar']   ?? 'avatar1.png';

        if (!$nombre || !$email || !$pass)    fail('Todos los campos son obligatorios');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Correo inválido');
        if (strlen($pass) < 6)                fail('La contraseña debe tener al menos 6 caracteres');

        $pdo = getDB();

        // ¿Email ya existe?
        $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) fail('Este correo ya tiene una cuenta registrada');

        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, avatar) VALUES (?,?,?,?)");
        $stmt->execute([$nombre, $email, $hash, $avatar]);
        $newId = (int) $pdo->lastInsertId();

        // Crear proyecto de Onboarding automáticamente
        $pdo->prepare("INSERT INTO proyectos (nombre, descripcion, color, icono, propietario_id) VALUES (?,?,?,?,?)")
            ->execute(['Mi primer proyecto', 'Proyecto de bienvenida a Finx-Task', '#18c8a0', '👋', $newId]);
        $proyId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO proyecto_miembros (proyecto_id, usuario_id, rol) VALUES (?,?,?)")
            ->execute([$proyId, $newId, 'admin']);

        // Tareas de onboarding
        $tareas = [
            ['Explorar el dashboard', 'done',  'alta'],
            ['Crear tu primer proyecto real', 'todo', 'alta'],
            ['Invitar a un compañero de equipo', 'todo', 'media'],
            ['Revisar las integraciones disponibles', 'todo', 'baja'],
        ];
        $ins = $pdo->prepare("INSERT INTO tareas (proyecto_id, creador_id, asignado_a, titulo, estado, prioridad) VALUES (?,?,?,?,?,?)");
        foreach ($tareas as [$titulo, $estado, $prioridad]) {
            $ins->execute([$proyId, $newId, $newId, $titulo, $estado, $prioridad]);
        }

        ok(['message' => 'Cuenta creada exitosamente', 'user_id' => $newId]);

    // ── LOGIN ─────────────────────────────────────────────────
    case 'login':
        $email = trim($_POST['email']    ?? '');
        $pass  =      $_POST['password'] ?? '';

        if (!$email || !$pass) fail('Correo y contraseña son obligatorios');

        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND activo = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pass, $user['password'])) {
            fail('Credenciales inválidas');
        }

        // Generar token de sesión (30 días)
        $token   = generateToken();
        $expira  = date('Y-m-d H:i:s', strtotime('+30 days'));
        $ip      = $_SERVER['REMOTE_ADDR'] ?? '';

        $pdo->prepare("INSERT INTO sesiones (usuario_id, token, expira_el, ip) VALUES (?,?,?,?)")
            ->execute([$user['id'], $token, $expira, $ip]);

        // Actualizar último login
        $pdo->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")
            ->execute([$user['id']]);

        // Iniciar sesión PHP también
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['nombre'];
        $_SESSION['user_avatar'] = $user['avatar'];

        ok([
            'token' => $token,
            'user'  => [
                'id'     => $user['id'],
                'name'   => $user['nombre'],
                'email'  => $user['email'],
                'avatar' => $user['avatar'],
                'rol'    => $user['rol'],
            ]
        ]);

    // ── LOGOUT ───────────────────────────────────────────────
    case 'logout':
        $token = $_POST['token'] ?? '';
        if ($token) {
            getDB()->prepare("DELETE FROM sesiones WHERE token = ?")->execute([$token]);
        }
        session_destroy();
        ok(['message' => 'Sesión cerrada']);

    // ── PERFIL DEL USUARIO AUTENTICADO ───────────────────────
    case 'profile':
        $user = requireAuth();

        $pdo  = getDB();

        // Estadísticas del usuario
        $stats = $pdo->prepare("
            SELECT
              COUNT(*) AS total_tareas,
              SUM(estado = 'done') AS completadas,
              SUM(estado != 'done' AND fecha_vence < CURDATE()) AS vencidas,
              SUM(estado != 'done' AND fecha_vence = CURDATE()) AS vencen_hoy
            FROM tareas WHERE asignado_a = ?
        ");
        $stats->execute([$user['id']]);
        $estadisticas = $stats->fetch();

        // Proyectos del usuario
        $proyStmt = $pdo->prepare("
            SELECT p.id, p.nombre, p.color, p.icono, pm.rol,
                   COUNT(t.id) AS total_tareas,
                   SUM(t.estado = 'done') AS tareas_hechas
            FROM proyecto_miembros pm
            JOIN proyectos p ON pm.proyecto_id = p.id
            LEFT JOIN tareas t ON t.proyecto_id = p.id
            WHERE pm.usuario_id = ? AND p.estado = 'activo'
            GROUP BY p.id
        ");
        $proyStmt->execute([$user['id']]);

        // Notificaciones no leídas
        $notifStmt = $pdo->prepare("
            SELECT id, tipo, titulo, mensaje, url, creado_el
            FROM notificaciones WHERE usuario_id = ? AND leida = 0
            ORDER BY creado_el DESC LIMIT 10
        ");
        $notifStmt->execute([$user['id']]);

        ok([
            'user'            => $user,
            'estadisticas'    => $estadisticas,
            'proyectos'       => $proyStmt->fetchAll(),
            'notificaciones'  => $notifStmt->fetchAll(),
        ]);

    // ── ACTUALIZAR PERFIL ─────────────────────────────────────
    case 'update_profile':
        $user   = requireAuth();
        $pdo    = getDB();
        $nombre = trim($_POST['nombre'] ?? '');
        $avatar = trim($_POST['avatar'] ?? '');

        if ($nombre) {
            $pdo->prepare("UPDATE usuarios SET nombre = ? WHERE id = ?")
                ->execute([$nombre, $user['id']]);
        }
        if ($avatar) {
            $pdo->prepare("UPDATE usuarios SET avatar = ? WHERE id = ?")
                ->execute([$avatar, $user['id']]);
        }

        // Cambio de contraseña
        $passActual = $_POST['password_actual'] ?? '';
        $passNueva  = $_POST['password_nueva']  ?? '';
        if ($passActual && $passNueva) {
            $row = $pdo->prepare("SELECT password FROM usuarios WHERE id = ?");
            $row->execute([$user['id']]);
            $dbPass = $row->fetchColumn();
            if (!password_verify($passActual, $dbPass)) fail('Contraseña actual incorrecta');
            if (strlen($passNueva) < 6) fail('Nueva contraseña muy corta');
            $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?")
                ->execute([password_hash($passNueva, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
        }

        ok(['message' => 'Perfil actualizado']);

    // ── MARCAR NOTIFICACIONES COMO LEÍDAS ────────────────────
    case 'read_notif':
        $user = requireAuth();
        $ids  = $_POST['ids'] ?? [];   // array de IDs o 'all'
        $pdo  = getDB();

        if ($ids === 'all' || $ids === ['all']) {
            $pdo->prepare("UPDATE notificaciones SET leida = 1 WHERE usuario_id = ?")
                ->execute([$user['id']]);
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE notificaciones SET leida = 1 WHERE id IN ($placeholders) AND usuario_id = ?")
                ->execute(array_merge($ids, [$user['id']]));
        }
        ok();

    // ── BUSCAR USUARIO POR EMAIL (para invitaciones) ──────────
    case 'search_user':
        requireAuth();
        $email = trim($_GET['email'] ?? '');
        if (!$email) fail('Email requerido');
        $stmt = getDB()->prepare("SELECT id, nombre, email, avatar FROM usuarios WHERE email = ? AND activo = 1");
        $stmt->execute([$email]);
        $found = $stmt->fetch();
        if (!$found) fail('Usuario no encontrado', 404);
        ok(['user' => $found]);

    default:
        fail('Acción no reconocida', 404);
}
