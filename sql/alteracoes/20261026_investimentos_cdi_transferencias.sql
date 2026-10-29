-- Investimentos indexados ao CDI, tributacao, aplicacao/resgate ligados a transferencias e indices economicos (26/10/2026).
-- Pode rodar mais de uma vez (MariaDB 10.5).
ALTER TABLE lancamentos ADD COLUMN IF NOT EXISTS investimento_id INT UNSIGNED NULL COMMENT 'transferencia de/para investimento (aplicacao ou resgate)' AFTER ajuste;
ALTER TABLE lancamentos ADD COLUMN IF NOT EXISTS sentido_invest VARCHAR(10) NULL COMMENT 'aplicacao = debita a conta; resgate = credita a conta' AFTER investimento_id;

ALTER TABLE investimentos ADD COLUMN IF NOT EXISTS indexador VARCHAR(10) NOT NULL DEFAULT 'manual' COMMENT 'manual | cdi | pre | poupanca' AFTER taxa_anual;
ALTER TABLE investimentos ADD COLUMN IF NOT EXISTS percentual_cdi DECIMAL(7,2) NOT NULL DEFAULT 100 COMMENT '% do CDI quando indexador = cdi' AFTER indexador;
ALTER TABLE investimentos ADD COLUMN IF NOT EXISTS tributacao VARCHAR(14) NOT NULL DEFAULT 'nenhuma' COMMENT 'ir_regressivo | isento | nenhuma' AFTER percentual_cdi;
ALTER TABLE investimentos ADD COLUMN IF NOT EXISTS data_vencimento DATE NULL AFTER tributacao;
ALTER TABLE investimentos ADD COLUMN IF NOT EXISTS liquidez VARCHAR(12) NOT NULL DEFAULT 'diaria' COMMENT 'diaria | vencimento' AFTER data_vencimento;

ALTER TABLE investimento_movs MODIFY tipo ENUM('aporte','resgate','rendimento','reajuste') NOT NULL;
ALTER TABLE investimento_movs MODIFY valor DECIMAL(14,2) NOT NULL COMMENT 'resgate = valor bruto; reajuste pode ser negativo';
ALTER TABLE investimento_movs ADD COLUMN IF NOT EXISTS ir DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER valor;
ALTER TABLE investimento_movs ADD COLUMN IF NOT EXISTS iof DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER ir;
ALTER TABLE investimento_movs ADD COLUMN IF NOT EXISTS lancamento_id INT UNSIGNED NULL AFTER iof;
ALTER TABLE investimento_movs ADD CONSTRAINT fk_mov_lanc FOREIGN KEY IF NOT EXISTS (lancamento_id) REFERENCES lancamentos(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS indices (
  serie VARCHAR(10) NOT NULL,
  data DATE NOT NULL,
  valor DECIMAL(14,8) NOT NULL,
  PRIMARY KEY (serie, data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS parametros (
  chave VARCHAR(60) NOT NULL PRIMARY KEY,
  valor VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- investimentos ja cadastrados que usam a taxa manual continuam iguais; ajustes de indexador e tributacao sao feitos na tela.
