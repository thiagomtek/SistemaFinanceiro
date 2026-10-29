-- Indices de consulta em lancamentos: series fixas/parceladas (grupo_id) e situacao (efetivado) sempre filtradas por perfil.
-- Pode rodar mais de uma vez (MariaDB 10.5: CREATE INDEX IF NOT EXISTS). Faca o backup do banco antes (ver ALTERACOES_DE_BANCO.md).
CREATE INDEX IF NOT EXISTS ix_lanc_grupo ON lancamentos (perfil_id, grupo_id);
CREATE INDEX IF NOT EXISTS ix_lanc_efet ON lancamentos (perfil_id, efetivado, data);
