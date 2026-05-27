<?php
// ============================================================
//  proyectos.php — Proyectos, Tareas, Chat, Miembros
//  Finx-Task Backend
// ============================================================

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Conexión ─────────────────────────────────────────────────
require_once __DIR__ . '/auth.php';   // reutiliza getDB(), ok(), fail(), requireAuth(), notificar()

// ── Enrutador ─────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user   = requireAuth();

switch ($action) {

    // ═══════════════════════════════════════════════════════════
    //  PROYECTOS
    // ═══════════════════════════════════════════════════════════

    // ── Listar proyectos del usuario ──────────────────────────
    case 'list_projects':
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT p.id, p.nombre, p.descripcion, p.color, p.icono, p.estado,
                   pm.rol, p.creado_el,
                   (SELECT COUNT(*) FROM tareas t WHERE t.proyecto_id = p.id) AS total_tareas,
                   (SELECT COUNT(*) FROM tareas t WHERE t.proyecto_id = p.id AND t.estado = 'done') AS tareas_hechas,
                   (SELECT COUNT(*) FROM proyecto_miembros m WHERE m.proyecto_id = p.id) AS total_miembros
            FROM proyecto_miembros pm
            JOIN proyectos p ON pm.proyecto_id = p.id
            WHERE pm.usuario_id = ? AND p.estado != 'archivado'
            ORDER BY p.creado_el DESC
        ");
        $stmt->execute([$user['id']]);
        ok(['projects' => $stmt->fetchAll()]);

    // ── Crear proyecto ────────────────────────────────────────
    case 'create_project':
        $nombre = trim($_POST['nombre'] ?? '');
        $desc   = trim($_POST['descripcion'] ?? '');
        $color  = trim($_POST['color'] ?? '#5b5ef4');
        $icono  = trim($_POST['icono'] ?? '📋');

        if (!$nombre) fail('El nombre del proyecto es obligatorio');

        $pdo = getDB();
        $pdo->prepare("INSERT INTO proyectos (nombre, descripcion, color, icono, propietario_id) VALUES (?,?,?,?,?)")
            ->execute([$nombre, $desc, $color, $icono, $user['id']]);
        $proyId = (int) $pdo->lastInsertId();

        // Agregar creador como admin
        $pdo->prepare("INSERT INTO proyecto_miembros (proyecto_id, usuario_id, rol) VALUES (?,?,?)")
            ->execute([$proyId, $user['id'], 'admin']);

        // Mensaje de sistema en chat
        $pdo->prepare("INSERT INTO mensajes_chat (proyecto_id, usuario_id, mensaje, tipo) VALUES (?,?,?,?)")
            ->execute([$proyId, $user['id'], "🚀 ¡Proyecto '$nombre' creado! Bienvenido al equipo.", 'sistema']);

        ok(['project_id' => $proyId, 'message' => 'Proyecto creado']);

    // ── Obtener detalle de un proyecto ────────────────────────
    case 'get_project':
        $proyId = (int) ($_GET['proyecto_id'] ?? 0);
        if (!$proyId) fail('proyecto_id requerido');
        $pdo = getDB();

        // Verificar que el usuario pertenece al proyecto
        $check = $pdo->prepare("SELECT rol FROM proyecto_miembros WHERE proyecto_id = ? AND usuario_id = ?");
        $check->execute([$proyId, $user['id']]);
        if (!$check->fetch()) fail('Sin acceso a este proyecto', 403);

        $stmt = $pdo->prepare("SELECT * FROM proyectos WHERE id = ?");
        $stmt->execute([$proyId]);
        $project = $stmt->fetch();

        ok(['project' => $project]);

    // ── Editar proyecto ───────────────────────────────────────
    case 'edit_project':
        $proyId = (int) ($_POST['proyecto_id'] ?? 0);
        if (!$proyId) fail('proyecto_id requerido');
        $pdo = getDB();

        // Solo admin del proyecto puede editar
        $check = $pdo->prepare("SELECT rol FROM proyecto_miembros WHERE proyecto_id = ? AND usuario_id = ?");
        $check->execute([$proyId, $user['id']]);
        $miembro = $check->fetch();
        if (!$miembro || $miembro['rol'] !== 'admin') fail('Solo el administrador puede editar el proyecto', 403);

        $fields = [];
        $params = [];
        foreach (['nombre','descripcion','color','icono','estado'] as $f) {
            if (isset($_POST[$f])) { $fields[] = "$f = ?"; $params[] = $_POST[$f]; }
        }
        if (!$fields) fail('Nada que actualizar');
        $params[] = $proyId;

        $pdo->prepare("UPDATE proyectos SET " . implode(',', $fields) . " WHERE id = ?")
            ->execute($params);
        ok(['message' => 'Proyecto actualizado']);

    // ── Archivar / eliminar proyecto ──────────────────────────
    case 'archive_project':
        $proyId = (int) ($_POST['proyecto_id'] ?? 0);
        if (!$proyId) fail('proyecto_id requerido');
        $pdo = getDB();

        $check = $pdo->prepare("SELECT propietario_id FROM proyectos WHERE id = ?");
        $check->execute([$proyId]);
        $p = $check->fetch();
        if (!$p || $p['propietario_id'] != $user['id']) fail('Solo el propietario puede archivar', 403);

        $pdo->prepare("UPDATE proyectos SET estado = 'archivado' WHERE id = ?")
            ->execute([$proyId]);
        ok(['message' => 'Proyecto archivado']);


    // ═══════════════════════════════════════════════════════════
    //  MIEMBROS
    // ═══════════════════════════════════════════════════════════

    // ── Listar miembros ───────────────────────────────────────
    case 'list_members':
        $proyId = (int) ($_GET['proyecto_id'] ?? 0);
        if (!$proyId) fail('proyecto_id requerido');

        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT u.id, u.nombre, u.email, u.avatar, pm.rol, pm.unido_el,
                   (SELECT COUNT(*) FROM tareas t WHERE t.proyecto_id = ? AND t.asignado_a = u.id) AS tareas_asignadas
            FROM proyecto_miembros pm
            JOIN usuarios u ON pm.usuario_id = u.id
            WHERE pm.proyecto_id = ?
            ORDER BY pm.rol DESC, u.nombre ASC
        ");
        $stmt->execute([$proyId, $proyId]);
        ok(['members' => $stmt->fetchAll()]);

    // ── Invitar miembro por email ─────────────────────────────
    case 'invite':
        $email  = trim($_POST['email']       ?? '');
        $proyId = (int) ($_POST['proyecto_id'] ?? 0);
        $rol    = trim($_POST['rol']          ?? 'editor');

        if (!$email || !$proyId) fail('Email y proyecto_id son obligatorios');
        if (!in_array($rol, ['editor','visor'])) $rol = 'editor';

        $pdo = getDB();

        // Verificar que quien invita es admin del proyecto
        $check = $pdo->prepare("SELECT rol FROM proyecto_miembros WHERE proyecto_id = ? AND usuario_id = ?");
        $check->execute([$proyId, $user['id']]);
        $invitador = $check->fetch();
        if (!$invitador || !in_array($invitador['rol'], ['admin'])) fail('Solo el administrador puede invitar', 403);

        // Buscar usuario a invitar
        $find = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE email = ? AND activo = 1");
        $find->execute([$email]);
        $target = $find->fetch();
        if (!$target) fail('No se encontró ningún usuario con ese correo');

        // ¿Ya es miembro?
        $exists = $pdo->prepare("SELECT id FROM proyecto_miembros WHERE proyecto_id = ? AND usuario_id = ?");
        $exists->execute([$proyId, $target['id']]);
        if ($exists->fetch()) fail('Este usuario ya es miembro del proyecto');

        // Agregar
        $pdo->prepare("INSERT INTO proyecto_miembros (proyecto_id, usuario_id, rol) VALUES (?,?,?)")
            ->execute([$proyId, $target['id'], $rol]);

        // Mensaje de sistema en chat
        $pdo->prepare("INSERT INTO mensajes_chat (proyecto_id, usuario_id, mensaje, tipo) VALUES (?,?,?,?)")
            ->execute([$proyId, $user['id'], "👋 {$target['nombre']} se unió al proyecto.", 'sistema']);

        // Notificación al usuario invitado
        $proyNombre = $pdo->prepare("SELECT nombre FROM proyectos WHERE id = ?");
        $proyNombre->execute([$proyId]);
        $pn = $proyNombre->fetchColumn();
        notificar($target['id'], 'invitacion', "Invitación al proyecto",
            "{$user['nombre']} te invitó a '$pn'", "finxtask-dashboard.html?proy=$proyId");

        ok(['message' => "¡{$target['nombre']} agregado al proyecto como $rol!"]);

    // ── Cambiar rol de miembro ────────────────────────────────
    case 'change_role':
        $proyId    = (int) ($_POST['proyecto_id'] ?? 0);
        $miembroId = (int) ($_POST['usuario_id']  ?? 0);
        $nuevoRol  = trim($_POST['rol']            ?? 'editor');

        if (!in_array($nuevoRol, ['admin','editor','visor'])) fail('Rol inválido');
        $pdo = getDB();

        $check = $pdo->prepare("SELECT rol FROM proyecto_miembros WHERE proyecto_id = ? AND usuario_id = ?");
        $check->execute([$proyId, $user['id']]);
        $m = $check->fetch();
        if (!$m || $m['rol'] !== 'admin') fail('Solo el admin puede cambiar roles', 403);

        $pdo->prepare("UPDATE proyecto_miembros SET rol = ? WHERE proyecto_id = ? AND usuario_id = ?")
            ->execute([$nuevoRol, $proyId, $miembroId]);
        ok(['message' => 'Rol actualizado']);

    // ── Eliminar miembro ──────────────────────────────────────
    case 'remove_member':
        $proyId    = (int) ($_POST['proyecto_id'] ?? 0);
        $miembroId = (int) ($_POST['usuario_id']  ?? 0);
        $pdo = getDB();

        $check = $pdo->prepare("SELECT rol FROM proyecto_miembros WHERE proyecto_id = ? AND usuario_id = ?");
        $check->execute([$proyId, $user['id']]);
        $m = $check->fetch();
        if (!$m || $m['rol'] !== 'admin') fail('Solo el admin puede eliminar miembros', 403);

        $pdo->prepare("DELETE FROM proyecto_miembros WHERE proyecto_id = ? AND usuario_id = ?")
            ->execute([$proyId, $miembroId]);
        ok(['message' => 'Miembro eliminado del proyecto']);


    // ═══════════════════════════════════════════════════════════
    //  TAREAS
    // ═══════════════════════════════════════════════════════════

    // ── Listar tareas de un proyecto ──────────────────────────
    case 'list_tasks':
        $proyId = (int) ($_GET['proyecto_id'] ?? 0);
        if (!$proyId) fail('proyecto_id requerido');

        $pdo  = getDB();

        // Verificar acceso
        $check = $pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id = ? AND usuario_id = ?");
        $check->execute([$proyId, $user['id']]);
        if (!$check->fetch()) fail('Sin acceso', 403);

        // Filtros opcionales
        $where   = ['t.proyecto_id = ?'];
        $params  = [$proyId];

        if (!empty($_GET['estado']))    { $where[] = 't.estado = ?';    $params[] = $_GET['estado']; }
        if (!empty($_GET['prioridad'])) { $where[] = 't.prioridad = ?'; $params[] = $_GET['prioridad']; }
        if (!empty($_GET['asignado']))  { $where[] = 't.asignado_a = ?'; $params[] = (int)$_GET['asignado']; }

        $sql = "
            SELECT t.id, t.titulo, t.descripcion, t.estado, t.prioridad,
                   t.fecha_vence, t.orden, t.creado_el, t.actualizado_el,
                   t.asignado_a, u.nombre AS asignado_nombre, u.avatar AS asignado_avatar,
                   (SELECT COUNT(*) FROM comentarios c WHERE c.tarea_id = t.id) AS total_comentarios
            FROM tareas t
            LEFT JOIN usuarios u ON t.asignado_a = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.estado ASC, t.prioridad DESC, t.fecha_vence ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        ok(['tasks' => $stmt->fetchAll()]);

    // ── Mis tareas (todos los proyectos) ─────────────────────
    case 'my_tasks':
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT t.id, t.titulo, t.estado, t.prioridad, t.fecha_vence,
                   p.id AS proyecto_id, p.nombre AS proyecto_nombre, p.color AS proyecto_color
            FROM tareas t
            JOIN proyectos p ON t.proyecto_id = p.id
            WHERE t.asignado_a = ?
            ORDER BY t.fecha_vence ASC, t.prioridad DESC
        ");
        $stmt->execute([$user['id']]);
        ok(['tasks' => $stmt->fetchAll()]);

    // ── Crear tarea ───────────────────────────────────────────
    case 'create_task':
        $proyId    = (int) ($_POST['proyecto_id'] ?? 0);
        $titulo    = trim($_POST['titulo']         ?? '');
        $desc      = trim($_POST['descripcion']    ?? '');
        $prioridad = trim($_POST['prioridad']      ?? 'media');
        $estado    = trim($_POST['estado']         ?? 'todo');
        $vence     = trim($_POST['fecha_vence']    ?? '');
        $asignado  = !empty($_POST['asignado_a'])  ? (int)$_POST['asignado_a'] : $user['id'];

        if (!$proyId || !$titulo) fail('proyecto_id y titulo son obligatorios');
        if (!in_array($prioridad, ['baja','media','alta'])) $prioridad = 'media';
        if (!in_array($estado, ['todo','progress','done'])) $estado = 'todo';

        $pdo = getDB();
        $check = $pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id = ? AND usuario_id = ?");
        $check->execute([$proyId, $user['id']]);
        if (!$check->fetch()) fail('Sin acceso', 403);

        $pdo->prepare("
            INSERT INTO tareas (proyecto_id, creador_id, asignado_a, titulo, descripcion, estado, prioridad, fecha_vence)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([$proyId, $user['id'], $asignado, $titulo, $desc, $estado, $prioridad, $vence ?: null]);
        $tareaId = (int) $pdo->lastInsertId();

        // Notificar al asignado (si es diferente al creador)
        if ($asignado !== $user['id']) {
            notificar($asignado, 'tarea_asignada', 'Nueva tarea asignada',
                "{$user['nombre']} te asignó: \"$titulo\"", "finxtask-dashboard.html");
        }

        ok(['task_id' => $tareaId, 'message' => 'Tarea creada']);

    // ── Editar tarea ──────────────────────────────────────────
    case 'edit_task':
        $tareaId = (int) ($_POST['tarea_id'] ?? 0);
        if (!$tareaId) fail('tarea_id requerido');

        $pdo = getDB();
        // Verificar que la tarea existe y el usuario tiene acceso al proyecto
        $t = $pdo->prepare("
            SELECT t.*, pm.rol FROM tareas t
            JOIN proyecto_miembros pm ON pm.proyecto_id = t.proyecto_id AND pm.usuario_id = ?
            WHERE t.id = ?
        ");
        $t->execute([$user['id'], $tareaId]);
        $tarea = $t->fetch();
        if (!$tarea) fail('Tarea no encontrada o sin acceso', 404);

        $fields = [];
        $params = [];
        $allowed = ['titulo','descripcion','estado','prioridad','fecha_vence','asignado_a'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $_POST)) {
                $fields[] = "$f = ?";
                $params[] = $_POST[$f] === '' ? null : $_POST[$f];
            }
        }
        if (!$fields) fail('Nada que actualizar');
        $params[] = $tareaId;

        $pdo->prepare("UPDATE tareas SET " . implode(',', $fields) . " WHERE id = ?")
            ->execute($params);

        // Si cambió el asignado, notificar
        if (isset($_POST['asignado_a']) && (int)$_POST['asignado_a'] !== (int)$tarea['asignado_a']) {
            notificar((int)$_POST['asignado_a'], 'tarea_asignada', 'Tarea reasignada',
                "{$user['nombre']} te reasignó: \"{$tarea['titulo']}\"");
        }

        ok(['message' => 'Tarea actualizada']);

    // ── Eliminar tarea ────────────────────────────────────────
    case 'delete_task':
        $tareaId = (int) ($_POST['tarea_id'] ?? 0);
        if (!$tareaId) fail('tarea_id requerido');

        $pdo = getDB();
        $check = $pdo->prepare("
            SELECT t.id FROM tareas t
            JOIN proyecto_miembros pm ON pm.proyecto_id = t.proyecto_id AND pm.usuario_id = ?
            WHERE t.id = ? AND (t.creador_id = ? OR pm.rol = 'admin')
        ");
        $check->execute([$user['id'], $tareaId, $user['id']]);
        if (!$check->fetch()) fail('Sin permiso para eliminar esta tarea', 403);

        $pdo->prepare("DELETE FROM tareas WHERE id = ?")->execute([$tareaId]);
        ok(['message' => 'Tarea eliminada']);

    // ── Reordenar tareas (Kanban drag & drop) ─────────────────
    case 'reorder_tasks':
        // Espera: JSON body con {items:[{id,estado,orden},...]}
        $body  = json_decode(file_get_contents('php://input'), true);
        $items = $body['items'] ?? [];
        if (!$items) fail('items requerido');

        $pdo  = getDB();
        $stmt = $pdo->prepare("UPDATE tareas SET estado = ?, orden = ? WHERE id = ?");
        foreach ($items as $item) {
            $stmt->execute([$item['estado'], $item['orden'], (int)$item['id']]);
        }
        ok();

    // ── Comentarios de una tarea ──────────────────────────────
    case 'get_comments':
        $tareaId = (int) ($_GET['tarea_id'] ?? 0);
        if (!$tareaId) fail('tarea_id requerido');
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT c.id, c.contenido, c.creado_el, u.id AS usuario_id, u.nombre, u.avatar
            FROM comentarios c JOIN usuarios u ON c.usuario_id = u.id
            WHERE c.tarea_id = ? ORDER BY c.creado_el ASC
        ");
        $stmt->execute([$tareaId]);
        ok(['comments' => $stmt->fetchAll()]);

    case 'add_comment':
        $tareaId   = (int) ($_POST['tarea_id']  ?? 0);
        $contenido = trim($_POST['contenido']    ?? '');
        if (!$tareaId || !$contenido) fail('tarea_id y contenido son obligatorios');

        $pdo = getDB();
        $pdo->prepare("INSERT INTO comentarios (tarea_id, usuario_id, contenido) VALUES (?,?,?)")
            ->execute([$tareaId, $user['id'], $contenido]);
        ok(['comment_id' => (int)$pdo->lastInsertId()]);


    // ═══════════════════════════════════════════════════════════
    //  CHAT
    // ═══════════════════════════════════════════════════════════

    // ── Obtener mensajes de chat ──────────────────────────────
    case 'get_chat':
        $proyId = (int) ($_GET['proyecto_id'] ?? 0);
        if (!$proyId) fail('proyecto_id requerido');

        $pdo   = getDB();
        $since = $_GET['since'] ?? '1970-01-01 00:00:00';   // para polling incremental

        $stmt = $pdo->prepare("
            SELECT m.id, m.mensaje, m.tipo, m.enviado_el,
                   u.id AS usuario_id, u.nombre, u.avatar
            FROM mensajes_chat m
            JOIN usuarios u ON m.usuario_id = u.id
            WHERE m.proyecto_id = ? AND m.enviado_el > ?
            ORDER BY m.enviado_el ASC
            LIMIT 100
        ");
        $stmt->execute([$proyId, $since]);
        ok(['messages' => $stmt->fetchAll()]);

    // ── Enviar mensaje de chat ────────────────────────────────
    case 'send_msg':
        $proyId  = (int) ($_POST['proyecto_id'] ?? 0);
        $mensaje = trim($_POST['mensaje']        ?? '');
        if (!$proyId || !$mensaje) fail('proyecto_id y mensaje son obligatorios');
        if (mb_strlen($mensaje) > 1000) fail('Mensaje demasiado largo');

        $pdo = getDB();

        // Verificar que es miembro
        $check = $pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id = ? AND usuario_id = ?");
        $check->execute([$proyId, $user['id']]);
        if (!$check->fetch()) fail('Sin acceso al proyecto', 403);

        $pdo->prepare("INSERT INTO mensajes_chat (proyecto_id, usuario_id, mensaje) VALUES (?,?,?)")
            ->execute([$proyId, $user['id'], $mensaje]);
        $msgId = (int) $pdo->lastInsertId();

        ok(['msg_id' => $msgId, 'enviado_el' => date('Y-m-d H:i:s')]);


    // ═══════════════════════════════════════════════════════════
    //  DASHBOARD — datos combinados
    // ═══════════════════════════════════════════════════════════
    case 'dashboard':
        $pdo = getDB();

        // Métricas globales del usuario
        $metrics = $pdo->prepare("
            SELECT
              SUM(t.estado != 'done') AS activas,
              SUM(t.estado = 'done')  AS completadas,
              SUM(t.estado != 'done' AND t.fecha_vence = CURDATE()) AS vencen_hoy,
              SUM(t.estado != 'done' AND t.fecha_vence < CURDATE()) AS vencidas
            FROM tareas t
            WHERE t.asignado_a = ?
        ");
        $metrics->execute([$user['id']]);

        // Proyectos del usuario
        $projs = $pdo->prepare("
            SELECT p.id, p.nombre, p.color, p.icono, pm.rol,
                   (SELECT COUNT(*) FROM tareas WHERE proyecto_id = p.id AND estado != 'done') AS pendientes
            FROM proyecto_miembros pm
            JOIN proyectos p ON pm.proyecto_id = p.id
            WHERE pm.usuario_id = ? AND p.estado = 'activo'
            ORDER BY p.creado_el DESC
        ");
        $projs->execute([$user['id']]);

        // Próximas 8 tareas con vencimiento
        $upcoming = $pdo->prepare("
            SELECT t.id, t.titulo, t.prioridad, t.fecha_vence,
                   p.nombre AS proyecto, p.color
            FROM tareas t JOIN proyectos p ON t.proyecto_id = p.id
            WHERE t.asignado_a = ? AND t.estado != 'done' AND t.fecha_vence IS NOT NULL
            ORDER BY t.fecha_vence ASC LIMIT 8
        ");
        $upcoming->execute([$user['id']]);

        // Notificaciones no leídas (badge count)
        $notifCount = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida = 0");
        $notifCount->execute([$user['id']]);

        ok([
            'metrics'       => $metrics->fetch(),
            'projects'      => $projs->fetchAll(),
            'upcoming'      => $upcoming->fetchAll(),
            'notif_count'   => (int) $notifCount->fetchColumn(),
        ]);

    default:
        fail('Acción no reconocida', 404);
}
