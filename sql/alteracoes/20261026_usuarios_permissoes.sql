-- Usuarios, papeis e permissoes por tela (26/10/2026). Pode rodar mais de uma vez (MariaDB 10.5).
-- Usuarios existentes viram administradores ativos (comportamento anterior).
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS papel VARCHAR(14) NOT NULL DEFAULT 'admin' COMMENT 'admin | editor | leitor | personalizado' AFTER senha_hash;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER papel;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS dono_id INT UNSIGNED NULL COMMENT 'usuario dono dos dados compartilhados' AFTER ativo;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS ultimo_acesso DATETIME NULL AFTER dono_id;

CREATE TABLE IF NOT EXISTS usuario_permissoes (
  usuario_id INT UNSIGNED NOT NULL,
  modulo VARCHAR(20) NOT NULL,
  nivel VARCHAR(6) NOT NULL COMMENT 'ver | editar',
  PRIMARY KEY (usuario_id, modulo),
  CONSTRAINT fk_perm_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- avisos do dia (sino de notificacoes)
CREATE TABLE IF NOT EXISTS notificacoes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  chave VARCHAR(80) NOT NULL,
  titulo VARCHAR(120) NOT NULL,
  mensagem VARCHAR(255) NOT NULL,
  url VARCHAR(160) NULL,
  criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lida_em DATETIME NULL,
  UNIQUE KEY uq_notif (usuario_id, chave),
  CONSTRAINT fk_notif_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
