<?php
// ============================================================
//  auth.php — Autenticación, perfil e invitaciones
//  Finx-Task Backend v2
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ── Config BD ─────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'finxtask_db');
define('DB_USER', 'root');
define('DB_PASS', '');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Error de conexión a la base de datos: '.$e->getMessage()]);
        exit;
    }
    return $pdo;
}

function ok(array $data = []): void {
    echo json_encode(array_merge(['status'=>'success'], $data)); exit;
}
function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['status'=>'error','message'=>$msg]); exit;
}
function requireAuth(): array {
    $token = trim(str_replace('Bearer ', '', $_SERVER['HTTP_X_AUTH_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($token) {
        $s = getDB()->prepare("SELECT u.id,u.nombre,u.email,u.avatar,u.banner_color,u.bio,u.cargo,u.rol
            FROM sesiones s JOIN usuarios u ON s.usuario_id=u.id
            WHERE s.token=? AND s.expira_el>NOW() AND u.activo=1");
        $s->execute([$token]);
        $u = $s->fetch();
        if ($u) return $u;
    }
    if (!empty($_SESSION['user_id'])) {
        $s = getDB()->prepare("SELECT id,nombre,email,avatar,banner_color,bio,cargo,rol FROM usuarios WHERE id=? AND activo=1");
        $s->execute([$_SESSION['user_id']]);
        $u = $s->fetch();
        if ($u) return $u;
    }
    fail('No autorizado', 401);
}
function notificar(int $uid, string $tipo, string $titulo, string $msg, string $url = ''): void {
    try {
        getDB()->prepare("INSERT INTO notificaciones(usuario_id,tipo,titulo,mensaje,url) VALUES(?,?,?,?,?)")
               ->execute([$uid,$tipo,$titulo,$msg,$url]);
    } catch(Exception $e) {}
}

// ── Router ────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── REGISTRO ───────────────────────────────────────────
    case 'register':
        $nombre = trim($_POST['name']     ?? '');
        $email  = trim($_POST['email']    ?? '');
        $pass   =      $_POST['password'] ?? '';
        $avatar =      $_POST['avatar']   ?? 'Felix';
        $cargo  =      $_POST['cargo']    ?? '';

        if (!$nombre||!$email||!$pass) fail('Todos los campos son obligatorios');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) fail('Correo inválido');
        if (strlen($pass)<6) fail('Contraseña mínimo 6 caracteres');

        $pdo = getDB();
        $chk = $pdo->prepare("SELECT id FROM usuarios WHERE email=?");
        $chk->execute([$email]);
        if ($chk->fetch()) fail('Este correo ya tiene una cuenta');

        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]);
        $pdo->prepare("INSERT INTO usuarios(nombre,email,password,avatar,cargo) VALUES(?,?,?,?,?)")
            ->execute([$nombre,$email,$hash,$avatar,$cargo]);
        $uid = (int)$pdo->lastInsertId();

        // Proyecto de onboarding
        $cod = strtoupper(substr(bin2hex(random_bytes(4)),0,8));
        $pdo->prepare("INSERT INTO proyectos(nombre,descripcion,color,icono,propietario_id,codigo_invita) VALUES(?,?,?,?,?,?)")
            ->execute(['Mi primer proyecto','Tu espacio de trabajo personal','#5b5ef4','🚀',$uid,$cod]);
        $pid = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO proyecto_miembros(proyecto_id,usuario_id,rol) VALUES(?,?,?)")->execute([$pid,$uid,'admin']);

        // Tareas de bienvenida
        $ins = $pdo->prepare("INSERT INTO tareas(proyecto_id,creador_id,asignado_a,titulo,estado,prioridad) VALUES(?,?,?,?,?,?)");
        foreach([
            ['Explorar el dashboard','done','alta'],
            ['Crear tu primer proyecto real','todo','alta'],
            ['Invitar a un compañero','todo','media'],
            ['Explorar el chat del equipo','todo','baja'],
        ] as [$t,$e,$p]) $ins->execute([$pid,$uid,$uid,$t,$e,$p]);

        // Nota de bienvenida
        $pdo->prepare("INSERT INTO notas(proyecto_id,usuario_id,titulo,contenido,color,fijada) VALUES(?,?,?,?,?,?)")
            ->execute([$pid,$uid,'¡Bienvenido a Finx-Task!','Usa este espacio para escribir ideas, recordatorios y notas rápidas de tu equipo.','#5b5ef4',1]);

        // Mensaje de sistema en chat
        $pdo->prepare("INSERT INTO mensajes_chat(proyecto_id,usuario_id,mensaje,tipo) VALUES(?,?,?,?)")
            ->execute([$pid,$uid,"👋 ¡Bienvenido al proyecto! Este es tu canal de comunicación.",'sistema']);

        ok(['message'=>'Cuenta creada','user_id'=>$uid]);

    // ── LOGIN ──────────────────────────────────────────────
    case 'login':
        $email = trim($_POST['email']    ?? '');
        $pass  =      $_POST['password'] ?? '';
        if (!$email||!$pass) fail('Correo y contraseña requeridos');

        $pdo = getDB();
        $s   = $pdo->prepare("SELECT * FROM usuarios WHERE email=? AND activo=1");
        $s->execute([$email]);
        $user = $s->fetch();
        if (!$user||!password_verify($pass,$user['password'])) fail('Credenciales inválidas');

        $token  = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', strtotime('+30 days'));
        $pdo->prepare("INSERT INTO sesiones(usuario_id,token,expira_el,ip) VALUES(?,?,?,?)")
            ->execute([$user['id'],$token,$expira,$_SERVER['REMOTE_ADDR']??'']);
        $pdo->prepare("UPDATE usuarios SET ultimo_login=NOW() WHERE id=?")->execute([$user['id']]);

        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['nombre'];

        ok([
            'token' => $token,
            'user'  => [
                'id'          => $user['id'],
                'name'        => $user['nombre'],
                'email'       => $user['email'],
                'avatar'      => $user['avatar'],
                'banner_color'=> $user['banner_color'],
                'bio'         => $user['bio'],
                'cargo'       => $user['cargo'],
                'rol'         => $user['rol'],
            ]
        ]);

    // ── LOGOUT ─────────────────────────────────────────────
    case 'logout':
        $token = $_POST['token'] ?? '';
        if ($token) getDB()->prepare("DELETE FROM sesiones WHERE token=?")->execute([$token]);
        session_destroy();
        ok(['message'=>'Sesión cerrada']);

    // ── VER PERFIL (propio o de otro usuario) ──────────────
    case 'get_profile':
        $me = requireAuth();
        $target_id = (int)($_GET['user_id'] ?? $me['id']);
        $pdo = getDB();

        $u = $pdo->prepare("SELECT id,nombre,email,avatar,banner_color,bio,cargo,rol,creado_el,ultimo_login FROM usuarios WHERE id=? AND activo=1");
        $u->execute([$target_id]);
        $profile = $u->fetch();
        if (!$profile) fail('Usuario no encontrado',404);

        // Estadísticas públicas
        $stats = $pdo->prepare("SELECT
            COUNT(*) AS total_tareas,
            SUM(estado='done') AS completadas,
            SUM(estado!='done' AND fecha_vence<CURDATE()) AS vencidas
          FROM tareas WHERE asignado_a=?");
        $stats->execute([$target_id]);

        // Proyectos compartidos
        $shared = $pdo->prepare("SELECT p.id,p.nombre,p.color,p.icono,pm.rol
          FROM proyecto_miembros pm JOIN proyectos p ON pm.proyecto_id=p.id
          WHERE pm.usuario_id=? AND p.estado='activo'
          ORDER BY p.creado_el DESC LIMIT 6");
        $shared->execute([$target_id]);

        ok(['profile'=>$profile,'stats'=>$stats->fetch(),'projects'=>$shared->fetchAll(),'is_me'=>($target_id==$me['id'])]);

    // ── ACTUALIZAR PERFIL ──────────────────────────────────
    case 'update_profile':
        $me = requireAuth();
        $pdo = getDB();
        $fields=[]; $params=[];
        foreach(['nombre','bio','cargo','avatar','banner_color'] as $f) {
            if (isset($_POST[$f])) { $fields[]="$f=?"; $params[]=$_POST[$f]; }
        }
        if ($fields) {
            $params[]=$me['id'];
            $pdo->prepare("UPDATE usuarios SET ".implode(',',$fields)." WHERE id=?")->execute($params);
        }
        // Cambio de contraseña
        $pa=$_POST['password_actual']??''; $pn=$_POST['password_nueva']??'';
        if ($pa&&$pn) {
            $r=$pdo->prepare("SELECT password FROM usuarios WHERE id=?"); $r->execute([$me['id']]);
            if (!password_verify($pa,$r->fetchColumn())) fail('Contraseña actual incorrecta');
            if (strlen($pn)<6) fail('Contraseña muy corta');
            $pdo->prepare("UPDATE usuarios SET password=? WHERE id=?")->execute([password_hash($pn,PASSWORD_BCRYPT,['cost'=>12]),$me['id']]);
        }
        ok(['message'=>'Perfil actualizado']);

    // ── NOTIFICACIONES ─────────────────────────────────────
    case 'get_notifs':
        $me = requireAuth();
        $s  = getDB()->prepare("SELECT * FROM notificaciones WHERE usuario_id=? ORDER BY creado_el DESC LIMIT 20");
        $s->execute([$me['id']]);
        $cnt= getDB()->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=? AND leida=0");
        $cnt->execute([$me['id']]);
        ok(['notificaciones'=>$s->fetchAll(),'no_leidas'=>(int)$cnt->fetchColumn()]);

    case 'read_notif':
        $me=$requireAuth=requireAuth(); $pdo=getDB();
        $ids=$_POST['ids']??'all';
        if($ids==='all') $pdo->prepare("UPDATE notificaciones SET leida=1 WHERE usuario_id=?")->execute([$me['id']]);
        else {
            $pl=implode(',',array_fill(0,count($ids),'?'));
            $pdo->prepare("UPDATE notificaciones SET leida=1 WHERE id IN($pl) AND usuario_id=?")->execute(array_merge($ids,[$me['id']]));
        }
        ok();

    // ── BUSCAR USUARIO ─────────────────────────────────────
    case 'search_user':
        requireAuth();
        $q=trim($_GET['q']??'');
        if(strlen($q)<2) fail('Ingresa al menos 2 caracteres');
        $s=getDB()->prepare("SELECT id,nombre,email,avatar,cargo FROM usuarios WHERE (email LIKE ? OR nombre LIKE ?) AND activo=1 LIMIT 8");
        $s->execute(["%$q%","%$q%"]);
        ok(['users'=>$s->fetchAll()]);

    // ── UNIRSE A PROYECTO CON CÓDIGO ───────────────────────
    case 'join_by_code':
        $me  = requireAuth();
        $cod = strtoupper(trim($_POST['codigo']??''));
        if(!$cod) fail('Código requerido');
        $pdo = getDB();
        $p   = $pdo->prepare("SELECT id,nombre,icono FROM proyectos WHERE codigo_invita=? AND estado='activo'");
        $p->execute([$cod]);
        $proy = $p->fetch();
        if(!$proy) fail('Código inválido o proyecto no encontrado');
        $ex=$pdo->prepare("SELECT id FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ex->execute([$proy['id'],$me['id']]);
        if($ex->fetch()) fail('Ya eres miembro de este proyecto');
        $pdo->prepare("INSERT INTO proyecto_miembros(proyecto_id,usuario_id,rol) VALUES(?,?,?)")->execute([$proy['id'],$me['id'],'editor']);
        $pdo->prepare("INSERT INTO mensajes_chat(proyecto_id,usuario_id,mensaje,tipo) VALUES(?,?,?,?)")
            ->execute([$proy['id'],$me['id'],"👋 {$me['nombre']} se unió al proyecto.",'sistema']);
        notificar($me['id'],'invitacion','Te uniste a un proyecto',"Ahora eres miembro de {$proy['nombre']}");
        ok(['message'=>"¡Te uniste a {$proy['icono']} {$proy['nombre']}!",'project'=>$proy]);

    // ── ACEPTAR/RECHAZAR INVITACIÓN POR TOKEN ──────────────
    case 'accept_invite':
        $me    = requireAuth();
        $token = trim($_GET['token']??$_POST['token']??'');
        if(!$token) fail('Token requerido');
        $pdo   = getDB();
        $inv   = $pdo->prepare("SELECT * FROM invitaciones WHERE token=? AND estado='pendiente' AND expira_el>NOW()");
        $inv->execute([$token]);
        $i = $inv->fetch();
        if(!$i) fail('Invitación inválida o expirada');

        $pdo->prepare("INSERT IGNORE INTO proyecto_miembros(proyecto_id,usuario_id,rol) VALUES(?,?,?)")
            ->execute([$i['proyecto_id'],$me['id'],$i['rol']]);
        $pdo->prepare("UPDATE invitaciones SET estado='aceptada' WHERE token=?")->execute([$token]);

        $pn=$pdo->prepare("SELECT nombre,icono FROM proyectos WHERE id=?"); $pn->execute([$i['proyecto_id']]);
        $proj=$pn->fetch();
        $pdo->prepare("INSERT INTO mensajes_chat(proyecto_id,usuario_id,mensaje,tipo) VALUES(?,?,?,?)")
            ->execute([$i['proyecto_id'],$me['id'],"👋 {$me['nombre']} aceptó la invitación.",'sistema']);
        notificar($i['invitador_id'],'invitacion_aceptada',"{$me['nombre']} aceptó tu invitación","Se unió a {$proj['nombre']}");
        ok(['message'=>"¡Bienvenido a {$proj['icono']} {$proj['nombre']}!",'project_id'=>$i['proyecto_id']]);

    //default:
        //fail('Acción no reconocida',404);
}
