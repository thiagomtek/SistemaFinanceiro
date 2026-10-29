-- Receita/despesa fixa sem fim (26/10/2026): marca a serie e a periodicidade para o sistema gerar as proximas ocorrencias.
-- Pode rodar mais de uma vez (MariaDB 10.5).
ALTER TABLE lancamentos ADD COLUMN IF NOT EXISTS recorrencia VARCHAR(10) NULL COMMENT 'fixa = sem fim, ate ser excluida' AFTER parcela_total;
ALTER TABLE lancamentos ADD COLUMN IF NOT EXISTS periodo VARCHAR(6) NULL COMMENT 'dia | semana | mes | ano (series fixas)' AFTER recorrencia;
