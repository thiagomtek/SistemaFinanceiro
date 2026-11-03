-- Cenario economico e risco do patrimonio (fase 2): manchetes do dia, leitura diaria e mensal do cenario (globais, como a tabela
-- indices) e foto diaria do risco de cada perfil. Pode rodar mais de uma vez (MariaDB 10.5).
-- Faca o backup do banco antes (ver ALTERACOES_DE_BANCO.md). Unica alteracao em tabela existente: investimentos.moeda (BRL ou USD).
ALTER TABLE investimentos ADD COLUMN IF NOT EXISTS moeda CHAR(3) NOT NULL DEFAULT 'BRL' AFTER liquidez;

CREATE TABLE IF NOT EXISTS cenario_noticias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data DATE NOT NULL,
  tema VARCHAR(20) NOT NULL,
  titulo VARCHAR(300) NOT NULL,
  fonte VARCHAR(120) NULL,
  url VARCHAR(700) NOT NULL,
  url_hash CHAR(40) NOT NULL,
  publicada_em DATETIME NULL,
  destaque TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_cenario_noticia (data, tema, url_hash),
  KEY ix_cenario_noticia_data (data, tema)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cenario_diario (
  data DATE NOT NULL PRIMARY KEY,
  resumo TEXT NOT NULL,
  temas_json MEDIUMTEXT NOT NULL,
  indicadores_json TEXT NOT NULL,
  origem VARCHAR(10) NOT NULL DEFAULT 'ia',
  modelo VARCHAR(60) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cenario_mensal (
  mes CHAR(7) NOT NULL PRIMARY KEY,
  resumo TEXT NOT NULL,
  perspectiva TEXT NOT NULL,
  indicadores_json TEXT NOT NULL,
  origem VARCHAR(10) NOT NULL DEFAULT 'ia',
  modelo VARCHAR(60) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS risco_snapshots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perfil_id INT UNSIGNED NOT NULL,
  data DATE NOT NULL,
  nivel_geral TINYINT NOT NULL,
  dimensoes_json MEDIUMTEXT NOT NULL,
  indicadores_json TEXT NOT NULL,
  exposicao_json TEXT NOT NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_risco_dia (perfil_id, data),
  CONSTRAINT fk_risco_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
