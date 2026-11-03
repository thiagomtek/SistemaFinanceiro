# Migrações de banco em produção

Banco: `financeiro` (MariaDB 10.5, contêiner `fin_db`). Qualquer DDL/DML em produção exige autorização explícita, backup prévio e registro aqui. SQL idempotente em `sql/migracoes/AAAAMMDD_descricao.sql`.

| Migração | Data | Descrição | Backup | Autorizada por | Status |
|---|---|---|---|---|---|
| `sql/schema.sql` | 26/10/2026 | Schema inicial (10 tabelas), criado automaticamente na primeira subida do volume `fin_db_data` em banco novo e vazio | n/a (banco novo) | Thiago (liberdade de atuação no servidor) | Aplicada; MariaDB 10.5.29 |
