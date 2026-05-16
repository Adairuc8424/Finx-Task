<?php
// ============================================================
//  proyectos.php — Proyectos, Tareas, Chat, Docs, Notas
//  Finx-Task Backend v2
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/auth.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user   = requireAuth();

switch ($action) {

    // ═══════════════════════════════════════════════════════
    //  DASHBOARD
    // ═══════════════════════════════════════════════════════
    case 'dashboard':
        $pdo = getDB();
        $m=$pdo->prepare("SELECT SUM(estado!='done') activas,SUM(estado='done') completadas,
            SUM(estado!='done' AND fecha_vence=CURDATE()) vencen_hoy,
            SUM(estado!='done' AND fecha_vence<CURDATE()) vencidas
            FROM tareas WHERE asignado_a=?");
        $m->execute([$user['id']]);
        $p=$pdo->prepare("SELECT p.id,p.nombre,p.color,p.icono,p.descripcion,pm.rol,p.codigo_invita,
            (SELECT COUNT(*) FROM tareas WHERE proyecto_id=p.id AND estado!='done') pendientes,
            (SELECT COUNT(*) FROM proyecto_miembros WHERE proyecto_id=p.id) miembros
            FROM proyecto_miembros pm JOIN proyectos p ON pm.proyecto_id=p.id
            WHERE pm.usuario_id=? AND p.estado='activo' ORDER BY p.creado_el DESC");
        $p->execute([$user['id']]);
        $u=$pdo->prepare("SELECT t.id,t.titulo,t.prioridad,t.fecha_vence,p.id proyecto_id,p.nombre proyecto_nombre,p.color proyecto_color
            FROM tareas t JOIN proyectos p ON t.proyecto_id=p.id
            WHERE t.asignado_a=? AND t.estado!='done' AND t.fecha_vence IS NOT NULL
            ORDER BY t.fecha_vence ASC LIMIT 8");
        $u->execute([$user['id']]);
        $nc=$pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=? AND leida=0");
        $nc->execute([$user['id']]);
        ok(['metrics'=>$m->fetch(),'projects'=>$p->fetchAll(),'upcoming'=>$u->fetchAll(),'notif_count'=>(int)$nc->fetchColumn()]);

    // ═══════════════════════════════════════════════════════
    //  PROYECTOS
    // ═══════════════════════════════════════════════════════
    case 'create_project':
        $nombre = trim($_POST['nombre']??'');
        if(!$nombre) fail('Nombre requerido');
        $pdo  = getDB();
        $cod  = strtoupper(substr(bin2hex(random_bytes(5)),0,8));
        $pdo->prepare("INSERT INTO proyectos(nombre,descripcion,color,icono,propietario_id,codigo_invita,visibilidad) VALUES(?,?,?,?,?,?,?)")
            ->execute([$nombre,$_POST['descripcion']??'',$_POST['color']??'#5b5ef4',$_POST['icono']??'📋',$user['id'],$cod,$_POST['visibilidad']??'privado']);
        $pid=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO proyecto_miembros(proyecto_id,usuario_id,rol) VALUES(?,?,?)")->execute([$pid,$user['id'],'admin']);
        $pdo->prepare("INSERT INTO mensajes_chat(proyecto_id,usuario_id,mensaje,tipo) VALUES(?,?,?,?)")
            ->execute([$pid,$user['id'],"🚀 Proyecto '{$nombre}' creado. ¡Bienvenido al equipo!",'sistema']);
        // README automático
        $pdo->prepare("INSERT INTO documentos(proyecto_id,autor_id,titulo,contenido,tipo) VALUES(?,?,?,?,?)")
            ->execute([$pid,$user['id'],'README','<h1>'.$nombre.'</h1><p>Descripción del proyecto.</p>','readme']);
        ok(['project_id'=>$pid,'codigo_invita'=>$cod,'message'=>'Proyecto creado']);

    case 'get_project':
        $pid=$_GET['proyecto_id']??0;
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); if(!$ac->fetch()) fail('Sin acceso',403);
        $p=$pdo->prepare("SELECT p.*,u.nombre propietario_nombre FROM proyectos p JOIN usuarios u ON p.propietario_id=u.id WHERE p.id=?");
        $p->execute([$pid]);
        ok(['project'=>$p->fetch()]);

    case 'edit_project':
        $pid=(int)($_POST['proyecto_id']??0);
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT rol FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); $m=$ac->fetch();
        if(!$m||$m['rol']!='admin') fail('Solo admin puede editar',403);
        $fs=[]; $ps=[];
        foreach(['nombre','descripcion','color','icono','estado','visibilidad'] as $f)
            if(isset($_POST[$f])){$fs[]="$f=?";$ps[]=$_POST[$f];}
        if(!$fs) fail('Nada que actualizar');
        $ps[]=$pid;
        $pdo->prepare("UPDATE proyectos SET ".implode(',',$fs)." WHERE id=?")->execute($ps);
        ok(['message'=>'Proyecto actualizado']);

    case 'list_projects':
        $s=getDB()->prepare("SELECT p.id,p.nombre,p.color,p.icono,p.descripcion,p.estado,p.codigo_invita,pm.rol,
            (SELECT COUNT(*) FROM tareas WHERE proyecto_id=p.id AND estado!='done') pendientes,
            (SELECT COUNT(*) FROM proyecto_miembros WHERE proyecto_id=p.id) miembros
            FROM proyecto_miembros pm JOIN proyectos p ON pm.proyecto_id=p.id
            WHERE pm.usuario_id=? AND p.estado!='archivado' ORDER BY p.creado_el DESC");
        $s->execute([$user['id']]);
        ok(['projects'=>$s->fetchAll()]);

    // ═══════════════════════════════════════════════════════
    //  MIEMBROS E INVITACIONES
    // ═══════════════════════════════════════════════════════
    case 'list_members':
        $pid=(int)($_GET['proyecto_id']??0);
        $pdo=getDB();
        $s=$pdo->prepare("SELECT u.id,u.nombre,u.email,u.avatar,u.cargo,pm.rol,pm.unido_el,
            (SELECT COUNT(*) FROM tareas WHERE proyecto_id=? AND asignado_a=u.id) tareas_asignadas
            FROM proyecto_miembros pm JOIN usuarios u ON pm.usuario_id=u.id WHERE pm.proyecto_id=? ORDER BY pm.rol DESC,u.nombre");
        $s->execute([$pid,$pid]);
        ok(['members'=>$s->fetchAll()]);

    case 'invite':
        $email=(trim($_POST['email']??''));
        $pid=(int)($_POST['proyecto_id']??0);
        $rol=$_POST['rol']??'editor';
        if(!$email||!$pid) fail('Email y proyecto requeridos');
        if(!in_array($rol,['admin','editor','visor'])) $rol='editor';
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT rol FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); $m=$ac->fetch();
        if(!$m||$m['rol']!='admin') fail('Solo admin puede invitar',403);
        // Buscar usuario
        $fu=$pdo->prepare("SELECT id,nombre FROM usuarios WHERE email=? AND activo=1");
        $fu->execute([$email]); $target=$fu->fetch();
        if(!$target) {
            // Crear invitación por email (usuario no registrado aún)
            $pn=$pdo->prepare("SELECT nombre,icono FROM proyectos WHERE id=?"); $pn->execute([$pid]);
            $proj=$pn->fetch();
            $tok=bin2hex(random_bytes(20));
            $exp=date('Y-m-d H:i:s',strtotime('+7 days'));
            $pdo->prepare("INSERT INTO invitaciones(proyecto_id,invitador_id,email,token,rol,expira_el) VALUES(?,?,?,?,?,?)")
                ->execute([$pid,$user['id'],$email,$tok,$rol,$exp]);
            ok(['message'=>"Invitación enviada a $email",'tipo'=>'email','token'=>$tok]);
        }
        $ex=$pdo->prepare("SELECT id FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ex->execute([$pid,$target['id']]); if($ex->fetch()) fail('Ya es miembro del proyecto');
        $pdo->prepare("INSERT INTO proyecto_miembros(proyecto_id,usuario_id,rol) VALUES(?,?,?)")->execute([$pid,$target['id'],$rol]);
        $pdo->prepare("INSERT INTO mensajes_chat(proyecto_id,usuario_id,mensaje,tipo) VALUES(?,?,?,?)")
            ->execute([$pid,$user['id'],"👋 {$target['nombre']} fue invitado al proyecto.",'sistema']);
        $pn=$pdo->prepare("SELECT nombre FROM proyectos WHERE id=?"); $pn->execute([$pid]);
        notificar($target['id'],'invitacion','Fuiste invitado a un proyecto',"{$user['nombre']} te invitó a ".$pn->fetchColumn());
        ok(['message'=>"{$target['nombre']} agregado como $rol",'tipo'=>'directo']);

    case 'remove_member':
        $pid=(int)($_POST['proyecto_id']??0); $mid=(int)($_POST['usuario_id']??0);
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT rol FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); $m=$ac->fetch();
        if(!$m||$m['rol']!='admin') fail('Solo admin puede eliminar miembros',403);
        $pdo->prepare("DELETE FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?")->execute([$pid,$mid]);
        ok(['message'=>'Miembro eliminado']);

    case 'change_role':
        $pid=(int)($_POST['proyecto_id']??0); $mid=(int)($_POST['usuario_id']??0); $rol=$_POST['rol']??'editor';
        if(!in_array($rol,['admin','editor','visor'])) fail('Rol inválido');
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT rol FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); $m=$ac->fetch();
        if(!$m||$m['rol']!='admin') fail('Solo admin puede cambiar roles',403);
        $pdo->prepare("UPDATE proyecto_miembros SET rol=? WHERE proyecto_id=? AND usuario_id=?")->execute([$rol,$pid,$mid]);
        ok(['message'=>'Rol actualizado']);

    // ═══════════════════════════════════════════════════════
    //  TAREAS
    // ═══════════════════════════════════════════════════════
    case 'list_tasks':
        $pid=(int)($_GET['proyecto_id']??0);
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); if(!$ac->fetch()) fail('Sin acceso',403);
        $where=["t.proyecto_id=?"]; $ps=[$pid];
        if(!empty($_GET['estado'])){$where[]="t.estado=?";$ps[]=$_GET['estado'];}
        if(!empty($_GET['prioridad'])){$where[]="t.prioridad=?";$ps[]=$_GET['prioridad'];}
        $s=$pdo->prepare("SELECT t.*,u.nombre asignado_nombre,u.avatar asignado_avatar,
            (SELECT COUNT(*) FROM comentarios WHERE tarea_id=t.id) comentarios
            FROM tareas t LEFT JOIN usuarios u ON t.asignado_a=u.id
            WHERE ".implode(' AND ',$where)." ORDER BY t.prioridad DESC,t.fecha_vence ASC");
        $s->execute($ps);
        ok(['tasks'=>$s->fetchAll()]);

    case 'my_tasks':
        $s=getDB()->prepare("SELECT t.*,p.id proyecto_id,p.nombre proyecto_nombre,p.color proyecto_color,u.nombre asignado_nombre
            FROM tareas t JOIN proyectos p ON t.proyecto_id=p.id LEFT JOIN usuarios u ON t.asignado_a=u.id
            WHERE t.asignado_a=? ORDER BY t.fecha_vence ASC,t.prioridad DESC");
        $s->execute([$user['id']]);
        ok(['tasks'=>$s->fetchAll()]);

    case 'create_task':
        $pid=(int)($_POST['proyecto_id']??0); $titulo=trim($_POST['titulo']??'');
        if(!$pid||!$titulo) fail('proyecto_id y título requeridos');
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); if(!$ac->fetch()) fail('Sin acceso',403);
        $asig=!empty($_POST['asignado_a'])?(int)$_POST['asignado_a']:$user['id'];
        $pdo->prepare("INSERT INTO tareas(proyecto_id,creador_id,asignado_a,titulo,descripcion,estado,prioridad,fecha_vence) VALUES(?,?,?,?,?,?,?,?)")
            ->execute([$pid,$user['id'],$asig,$titulo,$_POST['descripcion']??'',$_POST['estado']??'todo',$_POST['prioridad']??'media',$_POST['fecha_vence']??null?:null]);
        $tid=(int)$pdo->lastInsertId();
        if($asig!=$user['id']) notificar($asig,'tarea_asignada','Nueva tarea asignada',"{$user['nombre']} te asignó: $titulo");
        ok(['task_id'=>$tid,'message'=>'Tarea creada']);

    case 'edit_task':
        $tid=(int)($_POST['tarea_id']??0); if(!$tid) fail('tarea_id requerido');
        $pdo=getDB();
        $t=$pdo->prepare("SELECT t.*,pm.rol FROM tareas t JOIN proyecto_miembros pm ON pm.proyecto_id=t.proyecto_id AND pm.usuario_id=? WHERE t.id=?");
        $t->execute([$user['id'],$tid]); $tarea=$t->fetch();
        if(!$tarea) fail('Tarea no encontrada o sin acceso',404);
        $fs=[]; $ps=[];
        foreach(['titulo','descripcion','estado','prioridad','fecha_vence','asignado_a'] as $f)
            if(array_key_exists($f,$_POST)){$fs[]="$f=?";$ps[]=$_POST[$f]===''?null:$_POST[$f];}
        if(!$fs) fail('Nada que actualizar');
        $ps[]=$tid;
        $pdo->prepare("UPDATE tareas SET ".implode(',',$fs)." WHERE id=?")->execute($ps);
        if(isset($_POST['asignado_a'])&&(int)$_POST['asignado_a']!=(int)$tarea['asignado_a']&&(int)$_POST['asignado_a'])
            notificar((int)$_POST['asignado_a'],'tarea_asignada','Tarea reasignada',"{$user['nombre']} te reasignó: {$tarea['titulo']}");
        ok(['message'=>'Tarea actualizada']);

    case 'delete_task':
        $tid=(int)($_POST['tarea_id']??0);
        $pdo=getDB();
        $c=$pdo->prepare("SELECT t.id FROM tareas t JOIN proyecto_miembros pm ON pm.proyecto_id=t.proyecto_id AND pm.usuario_id=? WHERE t.id=? AND (t.creador_id=? OR pm.rol='admin')");
        $c->execute([$user['id'],$tid,$user['id']]); if(!$c->fetch()) fail('Sin permiso',403);
        $pdo->prepare("DELETE FROM tareas WHERE id=?")->execute([$tid]);
        ok(['message'=>'Tarea eliminada']);

    // Comentarios
    case 'get_comments':
        $tid=(int)($_GET['tarea_id']??0);
        $s=getDB()->prepare("SELECT c.*,u.nombre,u.avatar FROM comentarios c JOIN usuarios u ON c.usuario_id=u.id WHERE c.tarea_id=? ORDER BY c.creado_el ASC");
        $s->execute([$tid]);
        ok(['comments'=>$s->fetchAll()]);

    case 'add_comment':
        $tid=(int)($_POST['tarea_id']??0); $cont=trim($_POST['contenido']??'');
        if(!$tid||!$cont) fail('tarea_id y contenido requeridos');
        $pdo=getDB();
        $pdo->prepare("INSERT INTO comentarios(tarea_id,usuario_id,contenido) VALUES(?,?,?)")->execute([$tid,$user['id'],$cont]);
        ok(['comment_id'=>(int)$pdo->lastInsertId()]);

    // ═══════════════════════════════════════════════════════
    //  DOCUMENTOS
    // ═══════════════════════════════════════════════════════
    case 'list_docs':
        $pid=(int)($_GET['proyecto_id']??0);
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); if(!$ac->fetch()) fail('Sin acceso',403);
        $s=$pdo->prepare("SELECT d.id,d.titulo,d.tipo,d.creado_el,d.actualizado_el,u.nombre autor_nombre,u.avatar autor_avatar
            FROM documentos d JOIN usuarios u ON d.autor_id=u.id WHERE d.proyecto_id=? ORDER BY d.tipo,d.actualizado_el DESC");
        $s->execute([$pid]);
        ok(['docs'=>$s->fetchAll()]);

    case 'get_doc':
        $did=(int)($_GET['doc_id']??0);
        $pdo=getDB();
        $d=$pdo->prepare("SELECT d.*,u.nombre autor_nombre,u.avatar autor_avatar
            FROM documentos d JOIN usuarios u ON d.autor_id=u.id WHERE d.id=?");
        $d->execute([$did]);
        $doc=$d->fetch(); if(!$doc) fail('Documento no encontrado',404);
        $ac=$pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$doc['proyecto_id'],$user['id']]); if(!$ac->fetch()) fail('Sin acceso',403);
        ok(['doc'=>$doc]);

    case 'save_doc':
        $pid=(int)($_POST['proyecto_id']??0); $did=(int)($_POST['doc_id']??0);
        $titulo=trim($_POST['titulo']??'');
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT rol FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); $m=$ac->fetch();
        if(!$m||$m['rol']=='visor') fail('Sin permisos de escritura',403);
        if($did) {
            $pdo->prepare("UPDATE documentos SET titulo=?,contenido=?,actualizado_el=NOW() WHERE id=? AND proyecto_id=?")
                ->execute([$titulo,$_POST['contenido']??'',$did,$pid]);
            ok(['message'=>'Documento guardado']);
        } else {
            if(!$titulo) fail('Título requerido');
            $pdo->prepare("INSERT INTO documentos(proyecto_id,autor_id,titulo,contenido,tipo) VALUES(?,?,?,?,?)")
                ->execute([$pid,$user['id'],$titulo,$_POST['contenido']??'',$_POST['tipo']??'doc']);
            ok(['doc_id'=>(int)$pdo->lastInsertId(),'message'=>'Documento creado']);
        }

    case 'delete_doc':
        $did=(int)($_POST['doc_id']??0);
        $pdo=getDB();
        $d=$pdo->prepare("SELECT d.proyecto_id,d.autor_id,pm.rol FROM documentos d JOIN proyecto_miembros pm ON pm.proyecto_id=d.proyecto_id AND pm.usuario_id=? WHERE d.id=?");
        $d->execute([$user['id'],$did]); $doc=$d->fetch();
        if(!$doc) fail('No encontrado',404);
        if($doc['autor_id']!=$user['id']&&$doc['rol']!='admin') fail('Sin permiso',403);
        $pdo->prepare("DELETE FROM documentos WHERE id=?")->execute([$did]);
        ok(['message'=>'Documento eliminado']);

    // ═══════════════════════════════════════════════════════
    //  NOTAS
    // ═══════════════════════════════════════════════════════
    case 'list_notes':
        $pid=(int)($_GET['proyecto_id']??0);
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); if(!$ac->fetch()) fail('Sin acceso',403);
        $s=$pdo->prepare("SELECT n.*,u.nombre autor_nombre,u.avatar autor_avatar
            FROM notas n JOIN usuarios u ON n.usuario_id=u.id
            WHERE n.proyecto_id=? ORDER BY n.fijada DESC,n.actualizado_el DESC");
        $s->execute([$pid]);
        ok(['notes'=>$s->fetchAll()]);

    case 'save_note':
        $pid=(int)($_POST['proyecto_id']??0); $nid=(int)($_POST['nota_id']??0);
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); if(!$ac->fetch()) fail('Sin acceso',403);
        if($nid) {
            $n=$pdo->prepare("SELECT usuario_id FROM notas WHERE id=? AND proyecto_id=?");
            $n->execute([$nid,$pid]); $nota=$n->fetch();
            if(!$nota||$nota['usuario_id']!=$user['id']) fail('Sin permiso',403);
            $pdo->prepare("UPDATE notas SET titulo=?,contenido=?,color=?,fijada=?,actualizado_el=NOW() WHERE id=?")
                ->execute([$_POST['titulo']??'Sin título',$_POST['contenido']??'',$_POST['color']??'#f0a23a',(int)($_POST['fijada']??0),$nid]);
            ok(['message'=>'Nota guardada']);
        } else {
            $pdo->prepare("INSERT INTO notas(proyecto_id,usuario_id,titulo,contenido,color,fijada) VALUES(?,?,?,?,?,?)")
                ->execute([$pid,$user['id'],$_POST['titulo']??'Nueva nota',$_POST['contenido']??'',$_POST['color']??'#f0a23a',(int)($_POST['fijada']??0)]);
            ok(['note_id'=>(int)$pdo->lastInsertId(),'message'=>'Nota creada']);
        }

    case 'delete_note':
        $nid=(int)($_POST['nota_id']??0);
        $pdo=getDB();
        $n=$pdo->prepare("SELECT usuario_id FROM notas WHERE id=?");
        $n->execute([$nid]); $nota=$n->fetch();
        if(!$nota||$nota['usuario_id']!=$user['id']) fail('Sin permiso',403);
        $pdo->prepare("DELETE FROM notas WHERE id=?")->execute([$nid]);
        ok(['message'=>'Nota eliminada']);

    // ═══════════════════════════════════════════════════════
    //  CHAT
    // ═══════════════════════════════════════════════════════
    case 'get_chat':
        $pid=(int)($_GET['proyecto_id']??0);
        $since=$_GET['since']??'1970-01-01 00:00:00';
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); if(!$ac->fetch()) fail('Sin acceso',403);
        $s=$pdo->prepare("SELECT m.*,u.nombre,u.avatar FROM mensajes_chat m JOIN usuarios u ON m.usuario_id=u.id
            WHERE m.proyecto_id=? AND m.enviado_el>? ORDER BY m.enviado_el ASC LIMIT 100");
        $s->execute([$pid,$since]);
        ok(['messages'=>$s->fetchAll()]);

    case 'send_msg':
        $pid=(int)($_POST['proyecto_id']??0); $msg=trim($_POST['mensaje']??'');
        if(!$pid||!$msg) fail('proyecto_id y mensaje requeridos');
        if(mb_strlen($msg)>2000) fail('Mensaje demasiado largo');
        $pdo=getDB();
        $ac=$pdo->prepare("SELECT 1 FROM proyecto_miembros WHERE proyecto_id=? AND usuario_id=?");
        $ac->execute([$pid,$user['id']]); if(!$ac->fetch()) fail('Sin acceso',403);
        $pdo->prepare("INSERT INTO mensajes_chat(proyecto_id,usuario_id,mensaje) VALUES(?,?,?)")->execute([$pid,$user['id'],$msg]);
        ok(['msg_id'=>(int)$pdo->lastInsertId(),'enviado_el'=>date('Y-m-d H:i:s')]);

    default:
        fail('Acción no reconocida',404);
}
