-- Web Push (29/10/2026): assinaturas de notificacao por aparelho. Pode rodar mais de uma vez (MariaDB 10.5).
-- Faca o backup do banco antes (ver ALTERACOES_DE_BANCO.md). Nao altera tabelas existentes.
CREATE TABLE IF NOT EXISTS push_assinaturas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  endpoint TEXT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth VARCHAR(255) NOT NULL,
  user_agent VARCHAR(255) NULL,
  criada_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizada_em DATETIME NULL,
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY ix_push_usuario (usuario_id),
  CONSTRAINT fk_push_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
