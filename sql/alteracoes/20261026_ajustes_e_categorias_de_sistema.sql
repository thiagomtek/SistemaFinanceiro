-- Reajuste de saldo e de fatura (26/10/2026). Pode rodar mais de uma vez sem erro (MariaDB 10.5).
ALTER TABLE lancamentos ADD COLUMN IF NOT EXISTS ajuste VARCHAR(10) NULL COMMENT 'saldo = reajuste de conta; fatura = reajuste de fatura' AFTER fatura_id;
ALTER TABLE categorias ADD COLUMN IF NOT EXISTS sistema TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'categoria de sistema (Reajuste...), nao editavel' AFTER cor;
