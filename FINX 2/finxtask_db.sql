-- ============================================================
--  FINX-TASK — Esquema completo de base de datos
--  Motor: MariaDB 10.6+ / MySQL 8+
--  Charset: utf8mb4 (soporte completo de emojis y Unicode)
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
  avatar        VARCHAR(60)   NOT NULL DEFAULT 'avatar1.png',
  rol           ENUM('admin','miembro') NOT NULL DEFAULT 'miembro',
  activo        TINYINT(1)    NOT NULL DEFAULT 1,
  creado_el     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login  DATETIME      NULL,
  INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 2. PROYECTOS ───────────────────────────────────────────
CREATE TABLE proyectos (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(180)  NOT NULL,
  descripcion   TEXT          NULL,
  color         VARCHAR(7)    NOT NULL DEFAULT '#5b5ef4',  -- hex p. ej. #18c8a0
  icono         VARCHAR(10)   NOT NULL DEFAULT '📋',
  propietario_id INT UNSIGNED NOT NULL,
  estado        ENUM('activo','archivado','completado') NOT NULL DEFAULT 'activo',
  creado_el     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (propietario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_propietario (propietario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 3. MIEMBROS DE PROYECTO ────────────────────────────────
CREATE TABLE proyecto_miembros (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proyecto_id   INT UNSIGNED NOT NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  rol           ENUM('admin','editor','visor') NOT NULL DEFAULT 'editor',
  unido_el      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_miembro (proyecto_id, usuario_id),
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 4. TAREAS ──────────────────────────────────────────────
CREATE TABLE tareas (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proyecto_id   INT UNSIGNED NOT NULL,
  creador_id    INT UNSIGNED NOT NULL,
  asignado_a    INT UNSIGNED NULL,
  titulo        VARCHAR(255) NOT NULL,
  descripcion   TEXT         NULL,
  estado        ENUM('todo','progress','done') NOT NULL DEFAULT 'todo',
  prioridad     ENUM('baja','media','alta')    NOT NULL DEFAULT 'media',
  fecha_vence   DATE         NULL,
  orden         INT UNSIGNED NOT NULL DEFAULT 0,
  creado_el     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_el DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
  FOREIGN KEY (creador_id)  REFERENCES usuarios(id)  ON DELETE CASCADE,
  FOREIGN KEY (asignado_a)  REFERENCES usuarios(id)  ON DELETE SET NULL,
  INDEX idx_proyecto  (proyecto_id),
  INDEX idx_asignado  (asignado_a),
  INDEX idx_estado    (estado),
  INDEX idx_vence     (fecha_vence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 5. ETIQUETAS ───────────────────────────────────────────
CREATE TABLE etiquetas (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proyecto_id   INT UNSIGNED NOT NULL,
  nombre        VARCHAR(60)  NOT NULL,
  color         VARCHAR(7)   NOT NULL DEFAULT '#5b5ef4',
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tarea_etiquetas (
  tarea_id      INT UNSIGNED NOT NULL,
  etiqueta_id   INT UNSIGNED NOT NULL,
  PRIMARY KEY (tarea_id, etiqueta_id),
  FOREIGN KEY (tarea_id)    REFERENCES tareas(id)    ON DELETE CASCADE,
  FOREIGN KEY (etiqueta_id) REFERENCES etiquetas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 6. COMENTARIOS EN TAREAS ───────────────────────────────
CREATE TABLE comentarios (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tarea_id      INT UNSIGNED NOT NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  contenido     TEXT         NOT NULL,
  creado_el     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tarea_id)   REFERENCES tareas(id)    ON DELETE CASCADE,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)  ON DELETE CASCADE,
  INDEX idx_tarea (tarea_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 7. CHAT DE PROYECTO ────────────────────────────────────
CREATE TABLE mensajes_chat (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  proyecto_id   INT UNSIGNED NOT NULL,
  usuario_id    INT UNSIGNED NOT NULL,
  mensaje       TEXT         NOT NULL,
  tipo          ENUM('texto','sistema') NOT NULL DEFAULT 'texto',
  enviado_el    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (proyecto_id) REFERENCES proyectos(id) ON DELETE CASCADE,
  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)  ON DELETE CASCADE,
  INDEX idx_proyecto_chat (proyecto_id, enviado_el)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 8. NOTIFICACIONES ──────────────────────────────────────
CREATE TABLE notificaciones (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  tipo          VARCHAR(40)  NOT NULL,   -- 'tarea_asignada', 'comentario', 'invitacion'
  titulo        VARCHAR(180) NOT NULL,
  mensaje       VARCHAR(255) NOT NULL,
  leida         TINYINT(1)   NOT NULL DEFAULT 0,
  url           VARCHAR(255) NULL,
  creado_el     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_usuario_notif (usuario_id, leida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── 9. SESIONES (tokens API) ───────────────────────────────
CREATE TABLE sesiones (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  token         VARCHAR(64)  NOT NULL UNIQUE,
  expira_el     DATETIME     NOT NULL,
  ip            VARCHAR(45)  NULL,
  creado_el     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  DATOS DEMO
-- ============================================================

-- Usuario demo (password: demo123)
INSERT INTO usuarios (nombre, email, password, avatar, rol) VALUES
('Usuario Demo',  'demo@finxtask.io',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
 'avatar1.png', 'admin');

-- Proyecto demo
INSERT INTO proyectos (nombre, descripcion, color, icono, propietario_id) VALUES
('Finx-Task MVP', 'Desarrollo del MVP de la plataforma', '#5b5ef4', '🚀', 1);

-- Miembro (propietario también es miembro con rol admin)
INSERT INTO proyecto_miembros (proyecto_id, usuario_id, rol) VALUES (1, 1, 'admin');

-- Tareas demo
INSERT INTO tareas (proyecto_id, creador_id, asignado_a, titulo, estado, prioridad, fecha_vence) VALUES
(1, 1, 1, 'Diseñar pantalla de login y registro',   'done',     'alta',  CURDATE() - INTERVAL 2 DAY),
(1, 1, 1, 'Implementar sistema de autenticación',    'progress', 'alta',  CURDATE()),
(1, 1, 1, 'Conectar Google Calendar API',            'todo',     'media', CURDATE() + INTERVAL 2 DAY),
(1, 1, 1, 'Crear módulo de resúmenes con IA',        'todo',     'alta',  CURDATE() + INTERVAL 3 DAY),
(1, 1, 1, 'Diseñar tablero Kanban interactivo',      'done',     'media', CURDATE() - INTERVAL 1 DAY),
(1, 1, 1, 'Escribir documentación de la API',        'todo',     'baja',  CURDATE() + INTERVAL 7 DAY),
(1, 1, 1, 'Configurar Slack webhooks',               'todo',     'media', CURDATE() + INTERVAL 5 DAY),
(1, 1, 1, 'Pruebas de integración E2E',              'todo',     'alta',  CURDATE() + INTERVAL 9 DAY),
(1, 1, 1, 'Reunión de Sprint Review',                'todo',     'media', CURDATE() + INTERVAL 1 DAY),
(1, 1, 1, 'Actualizar modelo de base de datos',      'progress', 'alta',  CURDATE());

-- Mensaje de bienvenida en el chat
INSERT INTO mensajes_chat (proyecto_id, usuario_id, mensaje, tipo) VALUES
(1, 1, '👋 ¡Bienvenido al proyecto Finx-Task MVP! Este es el chat del equipo.', 'sistema');

-- ============================================================
--  VISTAS ÚTILES
-- ============================================================

-- Vista: tareas con datos del asignado y proyecto
CREATE OR REPLACE VIEW v_tareas AS
SELECT
  t.id, t.titulo, t.descripcion, t.estado, t.prioridad,
  t.fecha_vence, t.orden, t.creado_el, t.actualizado_el,
  t.proyecto_id, p.nombre AS proyecto_nombre, p.color AS proyecto_color,
  t.creador_id,
  t.asignado_a, u.nombre AS asignado_nombre, u.avatar AS asignado_avatar
FROM tareas t
JOIN proyectos p ON t.proyecto_id = p.id
LEFT JOIN usuarios u ON t.asignado_a = u.id;

-- Vista: miembros con datos del usuario
CREATE OR REPLACE VIEW v_miembros AS
SELECT
  pm.proyecto_id, pm.rol, pm.unido_el,
  u.id AS usuario_id, u.nombre, u.email, u.avatar, u.activo
FROM proyecto_miembros pm
JOIN usuarios u ON pm.usuario_id = u.id;
