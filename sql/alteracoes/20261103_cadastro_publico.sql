-- Cadastro publico de usuarios e administrador unico do sistema (v1.18.0). Pode rodar mais de uma vez (MariaDB 10.5).
-- Faca o backup do banco antes (ver ALTERACOES_DE_BANCO.md). So altera a tabela usuarios (4 colunas) e marca o administrador do sistema.
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS sistema_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER dono_id;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS cadastro VARCHAR(10) NOT NULL DEFAULT 'admin' AFTER sistema_admin;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS aprovacao VARCHAR(10) NOT NULL DEFAULT 'aprovado' AFTER cadastro;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS aprovado_em DATETIME NULL AFTER aprovacao;

-- O administrador do sistema e o administrador mais antigo (o dono dos dados atual). So marca se ainda nao houver um.
UPDATE usuarios SET sistema_admin = 1
 WHERE id = (SELECT m FROM (SELECT MIN(id) AS m FROM usuarios WHERE dono_id IS NULL AND papel = 'admin') x)
   AND (SELECT c FROM (SELECT COUNT(*) AS c FROM usuarios WHERE sistema_admin = 1) y) = 0;

-- Nenhum outro usuario pode ser administrador.
UPDATE usuarios SET papel = 'editor' WHERE papel = 'admin' AND sistema_admin = 0;
DELETE FROM usuario_permissoes WHERE modulo = 'usuarios' AND usuario_id IN (SELECT id FROM (SELECT id FROM usuarios WHERE sistema_admin = 0) z);
