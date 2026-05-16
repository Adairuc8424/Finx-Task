-- ============================================================
--  FINX-TASK — Esquema completo de base de datos v2
--  Motor: MariaDB 10.6+ / MySQL 8+
--  Incluye: usuarios, proyectos, tareas, chat, documentos,
--           notas, invitaciones, notificaciones, perfil
-- ============================================================

CREATE DATABASE IF NOT EXISTS finxtask_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE finxtask_db;

-- ─── 1. USUARIOS ────────────────────────────────────────────
CREATE TABLE usuarios (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(120)  NOT NULL,
  email         VARCHAR(180)  NOT NULL UNIQUE,
  password      VARCHAR(255)  NOT NULL,
  avatar        VARCHAR(80)   NOT NULL DEFAULT 'Felix',   -- seed para DiceBear
  banner_color  VARCHAR(7)    NOT NULL DEFAULT '#5b5ef4',
  bio           TEXT          NULL,
  cargo         VARCHAR(120)  NULL,
  rol           ENUM('admin','miembro') NOT NULL DEFAULT 'miembro',
  activo        TINYINT(1)    NOT NULL DEFAULT 1,
  creado_el     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login  DATETIME      NULL,
  INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 2. SESIONES ────────────────────────────────────────────
CREATE TABLE sesiones (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  token       VARCHAR(64)  NOT NULL UNIQUE,
  expira_el   DATETIME     NOT NULL,
  ip          VARCHAR(45)  NULL,
  creado_el   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 3. PROYECTOS ───────────────────────────────────────────
CREATE TABLE proyectos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(180) NOT NULL,
  descripcion     TEXT         NULL,
  color           VARCHAR(7)   NOT NULL DEFAULT '#5b5ef4',
  icono           VARCHAR(10)  NOT NULL DEFAULT '📋',
  propietario_id  INT UNSIGNED NOT NULL,
  estado          ENUM('activo','archivado','completado') NOT NULL DEFAULT 'activo',
  visibilidad     ENUM('privado','publico') NOT NULL DEFAULT 'privado',
  codigo_invita   VARCHAR(12)  NULL UNIQUE,   -- código público para unirse
  creado_el       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (propietario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_propietario (propietario_id),
  INDEX idx_codigo (codigo_invita)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 4. MIEMBROS ────────────────────────────────────────────
CREATE TABLE proyecto_miembros (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proyecto_id  INT UNSIGNED NOT NULL,
  usuario_id   INT UNSIGNED NOT NULL,
  rol          ENUM('admin','editor','visor') NOT NULL DEFAULT 'editor',
  unido_el     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_miembro (proyecto_id, usuario_id),
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 5. INVITACIONES ────────────────────────────────────────
CREATE TABLE invitaciones (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proyecto_id  INT UNSIGNED NOT NULL,
  invitador_id INT UNSIGNED NOT NULL,
  email        VARCHAR(180) NOT NULL,
  token        VARCHAR(40)  NOT NULL UNIQUE,
  rol          ENUM('admin','editor','visor') NOT NULL DEFAULT 'editor',
  estado       ENUM('pendiente','aceptada','rechazada','expirada') NOT NULL DEFAULT 'pendiente',
  expira_el    DATETIME     NOT NULL,
  creado_el    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (proyecto_id)  REFERENCES proyectos(id) ON DELETE CASCADE,
  FOREIGN KEY (invitador_id) REFERENCES usuarios(id)  ON DELETE CASCADE,
  INDEX idx_token_inv (token),
  INDEX idx_email_inv (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 6. TAREAS ──────────────────────────────────────────────
CREATE TABLE tareas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proyecto_id    INT UNSIGNED NOT NULL,
  creador_id     INT UNSIGNED NOT NULL,
  asignado_a     INT UNSIGNED NULL,
  titulo         VARCHAR(255) NOT NULL,
  descripcion    TEXT         NULL,
  estado         ENUM('todo','progress','done') NOT NULL DEFAULT 'todo',
  prioridad      ENUM('baja','media','alta')    NOT NULL DEFAULT 'media',
  fecha_vence    DATE         NULL,
  orden          INT UNSIGNED NOT NULL DEFAULT 0,
  creado_el      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_el DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
  FOREIGN KEY (creador_id)  REFERENCES usuarios(id)  ON DELETE CASCADE,
  FOREIGN KEY (asignado_a)  REFERENCES usuarios(id)  ON DELETE SET NULL,
  INDEX idx_proyecto (proyecto_id),
  INDEX idx_asignado (asignado_a),
  INDEX idx_vence    (fecha_vence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 7. COMENTARIOS EN TAREAS ───────────────────────────────
CREATE TABLE comentarios (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tarea_id    INT UNSIGNED NOT NULL,
  usuario_id  INT UNSIGNED NOT NULL,
  contenido   TEXT         NOT NULL,
  creado_el   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tarea_id)   REFERENCES tareas(id)   ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_tarea (tarea_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 8. DOCUMENTOS ──────────────────────────────────────────
CREATE TABLE documentos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proyecto_id    INT UNSIGNED NOT NULL,
  autor_id       INT UNSIGNED NOT NULL,
  titulo         VARCHAR(255) NOT NULL,
  contenido      LONGTEXT     NULL,              -- HTML / Markdown guardado
  tipo           ENUM('doc','wiki','readme') NOT NULL DEFAULT 'doc',
  creado_el      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_el DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
  FOREIGN KEY (autor_id)    REFERENCES usuarios(id)  ON DELETE CASCADE,
  INDEX idx_proy_doc (proyecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 9. NOTAS ───────────────────────────────────────────────
CREATE TABLE notas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proyecto_id    INT UNSIGNED NOT NULL,
  usuario_id     INT UNSIGNED NOT NULL,
  titulo         VARCHAR(255) NOT NULL DEFAULT 'Nota sin título',
  contenido      TEXT         NULL,
  color          VARCHAR(7)   NOT NULL DEFAULT '#f0a23a',
  fijada         TINYINT(1)   NOT NULL DEFAULT 0,
  creado_el      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_el DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE,
  INDEX idx_proy_nota (proyecto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 10. CHAT ───────────────────────────────────────────────
CREATE TABLE mensajes_chat (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proyecto_id  INT UNSIGNED NOT NULL,
  usuario_id   INT UNSIGNED NOT NULL,
  mensaje      TEXT         NOT NULL,
  tipo         ENUM('texto','sistema','archivo') NOT NULL DEFAULT 'texto',
  enviado_el   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE,
  INDEX idx_chat (proyecto_id, enviado_el)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 11. NOTIFICACIONES ─────────────────────────────────────
CREATE TABLE notificaciones (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  tipo        VARCHAR(40)  NOT NULL,
  titulo      VARCHAR(180) NOT NULL,
  mensaje     VARCHAR(255) NOT NULL,
  leida       TINYINT(1)   NOT NULL DEFAULT 0,
  url         VARCHAR(255) NULL,
  creado_el   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_notif (usuario_id, leida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 12. ETIQUETAS ──────────────────────────────────────────
CREATE TABLE etiquetas (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proyecto_id INT UNSIGNED NOT NULL,
  nombre      VARCHAR(60)  NOT NULL,
  color       VARCHAR(7)   NOT NULL DEFAULT '#5b5ef4',
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tarea_etiquetas (
  tarea_id    INT UNSIGNED NOT NULL,
  etiqueta_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (tarea_id, etiqueta_id),
  FOREIGN KEY (tarea_id)    REFERENCES tareas(id)    ON DELETE CASCADE,
  FOREIGN KEY (etiqueta_id) REFERENCES etiquetas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  VISTAS
-- ============================================================
CREATE OR REPLACE VIEW v_tareas AS
SELECT t.*, p.nombre AS proyecto_nombre, p.color AS proyecto_color,
       u.nombre AS asignado_nombre, u.avatar AS asignado_avatar
FROM tareas t
JOIN proyectos p ON t.proyecto_id = p.id
LEFT JOIN usuarios u ON t.asignado_a = u.id;

CREATE OR REPLACE VIEW v_miembros AS
SELECT pm.proyecto_id, pm.rol, pm.unido_el,
       u.id AS usuario_id, u.nombre, u.email, u.avatar, u.cargo, u.activo
FROM proyecto_miembros pm
JOIN usuarios u ON pm.usuario_id = u.id;

-- ============================================================
--  DATOS DEMO
--  password para AMBOS usuarios: demo123
-- ============================================================
INSERT INTO usuarios (nombre, email, password, avatar, cargo, bio, rol) VALUES
('Demo Admin',  'demo@finxtask.io',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'Felix', 'Product Manager',
 'Bienvenido al demo de Finx-Task. Soy el usuario de prueba.', 'admin'),
('Ana García',  'ana@finxtask.io',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'Aneka', 'Frontend Developer',
 'Desarrolladora frontend apasionada por UX.', 'miembro');

INSERT INTO proyectos (nombre, descripcion, color, icono, propietario_id, codigo_invita) VALUES
('Finx-Task MVP', 'Desarrollo del MVP de la plataforma colaborativa', '#5b5ef4', '🚀', 1, 'DEMO2025'),
('Diseño UX/UI',  'Rediseño de la experiencia de usuario', '#18c8a0', '🎨', 1, 'UXTEAM01');

INSERT INTO proyecto_miembros (proyecto_id, usuario_id, rol) VALUES
(1, 1, 'admin'), (1, 2, 'editor'),
(2, 1, 'admin'), (2, 2, 'admin');

INSERT INTO tareas (proyecto_id, creador_id, asignado_a, titulo, estado, prioridad, fecha_vence) VALUES
(1,1,1,'Diseñar sistema de autenticación',       'done',     'alta',  CURDATE()-INTERVAL 2 DAY),
(1,1,1,'Implementar base de datos',               'done',     'alta',  CURDATE()-INTERVAL 1 DAY),
(1,1,2,'Conectar Google Calendar API',            'progress', 'media', CURDATE()),
(1,1,1,'Crear módulo de resúmenes con IA',        'todo',     'alta',  CURDATE()+INTERVAL 3 DAY),
(1,1,2,'Diseñar tablero Kanban interactivo',      'todo',     'media', CURDATE()+INTERVAL 2 DAY),
(1,1,1,'Escribir documentación de la API',        'todo',     'baja',  CURDATE()+INTERVAL 7 DAY),
(2,1,2,'Crear wireframes de dashboard',           'done',     'alta',  CURDATE()-INTERVAL 3 DAY),
(2,1,2,'Diseñar sistema de colores',              'progress', 'media', CURDATE()+INTERVAL 1 DAY),
(2,1,1,'Preparar prototipo para pruebas',         'todo',     'alta',  CURDATE()+INTERVAL 4 DAY);

INSERT INTO mensajes_chat (proyecto_id, usuario_id, mensaje, tipo) VALUES
(1,1,'👋 ¡Bienvenidos al proyecto Finx-Task MVP! Este es nuestro canal de comunicación.','sistema'),
(1,2,'¡Hola equipo! Lista para empezar con las integraciones.','texto'),
(1,1,'Perfecto Ana. Empecemos con el módulo de calendario esta semana.','texto'),
(2,1,'🎨 Proyecto de diseño iniciado. Compartamos referencias aquí.','sistema'),
(2,2,'Ya subí los primeros wireframes al documento del proyecto.','texto');

INSERT INTO documentos (proyecto_id, autor_id, titulo, contenido, tipo) VALUES
(1,1,'README del proyecto',
'<h1>🚀 Finx-Task MVP</h1><p>Plataforma colaborativa de gestión de tareas con IA integrada.</p><h2>Stack tecnológico</h2><ul><li>Frontend: HTML5, CSS3, JavaScript</li><li>Backend: PHP 8.2 + MariaDB</li><li>IA: Claude API (Anthropic)</li></ul><h2>Cómo empezar</h2><p>1. Importa <code>finxtask_db.sql</code> en MariaDB<br>2. Configura credenciales en <code>auth.php</code><br>3. Abre <code>finxtask-auth.html</code></p>',
'readme'),
(1,1,'Guía de contribución',
'<h1>Guía de contribución</h1><p>Sigue estas normas para contribuir al proyecto.</p><h2>Ramas</h2><ul><li><code>main</code> — producción</li><li><code>dev</code> — desarrollo</li><li><code>feature/xxx</code> — nuevas funciones</li></ul><h2>Commits</h2><p>Usa el formato: <code>tipo(alcance): descripción</code></p>',
'doc'),
(2,2,'Referencias de diseño',
'<h1>🎨 Referencias visuales</h1><p>Inspiración y lineamientos para el rediseño.</p><h2>Paleta de colores</h2><p>Primario: <strong>#5b5ef4</strong> — Secundario: <strong>#18c8a0</strong></p><h2>Tipografía</h2><p>Headings: Syne 700/800 — Body: DM Sans 300/400</p>',
'wiki');

INSERT INTO notas (proyecto_id, usuario_id, titulo, contenido, color, fijada) VALUES
(1,1,'Ideas para el asistente IA','- Resúmenes automáticos de sprint\n- Sugerencias de prioridad\n- Detección de tareas bloqueadas\n- Generación de subtareas','#5b5ef4',1),
(1,2,'Pendientes de integración','Google Calendar: OAuth configurado ✓\nSlack: webhook pendiente\nTeams: por revisar','#f0a23a',0),
(2,2,'Feedback de pruebas de usabilidad','Los usuarios quieren:\n- Dashboard más limpio\n- Kanban con colores personalizables\n- Modo claro opcional','#18c8a0',1);
