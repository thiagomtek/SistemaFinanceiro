-- Ignorar transacao, observacao, anexos e filtros salvos (26/10/2026). Pode rodar mais de uma vez (MariaDB 10.5).
ALTER TABLE lancamentos ADD COLUMN IF NOT EXISTS ignorar TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'transacao ignorada: nao entra em saldos nem totais' AFTER ajuste;
ALTER TABLE lancamentos ADD COLUMN IF NOT EXISTS observacao VARCHAR(255) NULL AFTER tags;

CREATE TABLE IF NOT EXISTS anexos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perfil_id INT UNSIGNED NOT NULL,
  lancamento_id INT UNSIGNED NOT NULL,
  nome VARCHAR(160) NOT NULL,
  arquivo VARCHAR(80) NOT NULL,
  mime VARCHAR(80) NOT NULL,
  tamanho INT UNSIGNED NOT NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_anexo_lanc (lancamento_id),
  CONSTRAINT fk_anexo_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE,
  CONSTRAINT fk_anexo_lanc FOREIGN KEY (lancamento_id) REFERENCES lancamentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS filtros_salvos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perfil_id INT UNSIGNED NOT NULL,
  nome VARCHAR(60) NOT NULL,
  consulta VARCHAR(600) NOT NULL,
  UNIQUE KEY uq_filtro_nome (perfil_id, nome),
  CONSTRAINT fk_filtro_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
