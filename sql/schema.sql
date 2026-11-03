-- Sistema Financeiro - schema MariaDB 10.5 (utf8mb4)
-- Aplicado automaticamente na primeira subida do container (docker-entrypoint-initdb.d).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  papel VARCHAR(14) NOT NULL DEFAULT 'admin' COMMENT 'admin | editor | leitor | personalizado',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  dono_id INT UNSIGNED NULL COMMENT 'usuario dono dos dados compartilhados',
  ultimo_acesso DATETIME NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS usuario_permissoes (
  usuario_id INT UNSIGNED NOT NULL,
  modulo VARCHAR(20) NOT NULL,
  nivel VARCHAR(6) NOT NULL COMMENT 'ver | editar',
  PRIMARY KEY (usuario_id, modulo),
  CONSTRAINT fk_perm_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Perfil financeiro = "carteira" separada (pessoa fisica ou juridica). Todo dado pertence a um perfil.
CREATE TABLE IF NOT EXISTS perfis (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  nome VARCHAR(120) NOT NULL,
  tipo ENUM('PF','PJ') NOT NULL DEFAULT 'PF',
  documento VARCHAR(20) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_perfis_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perfil_id INT UNSIGNED NOT NULL,
  nome VARCHAR(80) NOT NULL,
  tipo ENUM('corrente','poupanca','carteira','investimento','outro') NOT NULL DEFAULT 'corrente',
  saldo_inicial DECIMAL(14,2) NOT NULL DEFAULT 0,
  ativa TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_conta_nome (perfil_id, nome),
  CONSTRAINT fk_contas_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categorias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perfil_id INT UNSIGNED NOT NULL,
  pai_id INT UNSIGNED NULL,
  nome VARCHAR(80) NOT NULL,
  tipo ENUM('receita','despesa') NOT NULL,
  cor CHAR(7) NOT NULL DEFAULT '#64748b',
  sistema TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'categoria de sistema (Reajuste...), nao editavel',
  UNIQUE KEY uq_categoria (perfil_id, tipo, pai_id, nome),
  CONSTRAINT fk_cat_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE,
  CONSTRAINT fk_cat_pai FOREIGN KEY (pai_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cartoes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perfil_id INT UNSIGNED NOT NULL,
  nome VARCHAR(80) NOT NULL,
  conta_pagamento_id INT UNSIGNED NULL,
  limite DECIMAL(14,2) NOT NULL DEFAULT 0,
  dia_fechamento TINYINT UNSIGNED NOT NULL DEFAULT 1,
  dia_vencimento TINYINT UNSIGNED NOT NULL DEFAULT 10,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cartao_nome (perfil_id, nome),
  CONSTRAINT fk_cartoes_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE,
  CONSTRAINT fk_cartoes_conta FOREIGN KEY (conta_pagamento_id) REFERENCES contas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fatura paga: o pagamento gera uma despesa na conta (lancamentos.fatura_id).
CREATE TABLE IF NOT EXISTS faturas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cartao_id INT UNSIGNED NOT NULL,
  mes CHAR(7) NOT NULL COMMENT 'YYYY-MM do vencimento',
  valor_pago DECIMAL(14,2) NOT NULL,
  data_pagamento DATE NOT NULL,
  conta_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_fatura (cartao_id, mes),
  CONSTRAINT fk_fat_cartao FOREIGN KEY (cartao_id) REFERENCES cartoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_fat_conta FOREIGN KEY (conta_id) REFERENCES contas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS importacoes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perfil_id INT UNSIGNED NOT NULL,
  arquivo VARCHAR(200) NOT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'planilha',
  qtd_importados INT NOT NULL DEFAULT 0,
  qtd_duplicados INT NOT NULL DEFAULT 0,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_imp_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- valor sempre positivo; o sentido vem de "tipo".
-- conta_id: conta afetada (origem na transferencia). Compra no cartao usa cartao_id e deixa conta_id nulo.
CREATE TABLE IF NOT EXISTS lancamentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perfil_id INT UNSIGNED NOT NULL,
  tipo ENUM('receita','despesa','transferencia') NOT NULL,
  descricao VARCHAR(160) NOT NULL,
  valor DECIMAL(14,2) NOT NULL,
  data DATE NOT NULL,
  efetivado TINYINT(1) NOT NULL DEFAULT 0,
  conta_id INT UNSIGNED NULL,
  conta_destino_id INT UNSIGNED NULL,
  cartao_id INT UNSIGNED NULL,
  fatura_mes CHAR(7) NULL COMMENT 'YYYY-MM do vencimento da fatura (compras no cartao)',
  fatura_id INT UNSIGNED NULL COMMENT 'preenchido no lancamento que paga a fatura',
  ajuste VARCHAR(10) NULL COMMENT 'saldo = reajuste de conta; fatura = reajuste de fatura',
  investimento_id INT UNSIGNED NULL COMMENT 'transferencia de/para investimento (aplicacao ou resgate)',
  sentido_invest VARCHAR(10) NULL COMMENT 'aplicacao = debita a conta; resgate = credita a conta',
  ignorar TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'sem uso: a funcao Ignorar foi retirada (coluna mantida para nao mexer na estrutura em producao)',
  categoria_id INT UNSIGNED NULL,
  tags VARCHAR(200) NULL,
  observacao VARCHAR(255) NULL,
  grupo_id VARCHAR(40) NULL COMMENT 'agrupa repeticoes/parcelas',
  parcela_num SMALLINT NULL,
  parcela_total SMALLINT NULL,
  recorrencia VARCHAR(10) NULL COMMENT 'fixa = sem fim, ate ser excluida',
  periodo VARCHAR(6) NULL COMMENT 'dia | semana | mes | ano (series fixas)',
  importacao_id INT UNSIGNED NULL,
  hash_unico CHAR(40) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lanc_hash (perfil_id, hash_unico),
  KEY ix_lanc_data (perfil_id, data),
  KEY ix_lanc_fatura (cartao_id, fatura_mes),
  KEY ix_lanc_grupo (perfil_id, grupo_id),
  KEY ix_lanc_efet (perfil_id, efetivado, data),
  CONSTRAINT fk_lanc_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE,
  CONSTRAINT fk_lanc_conta FOREIGN KEY (conta_id) REFERENCES contas(id),
  CONSTRAINT fk_lanc_destino FOREIGN KEY (conta_destino_id) REFERENCES contas(id),
  CONSTRAINT fk_lanc_cartao FOREIGN KEY (cartao_id) REFERENCES cartoes(id),
  CONSTRAINT fk_lanc_cat FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS investimentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  perfil_id INT UNSIGNED NOT NULL,
  nome VARCHAR(120) NOT NULL,
  tipo ENUM('renda_fixa','acoes','fii','fundos','cripto','previdencia','outro') NOT NULL DEFAULT 'renda_fixa',
  instituicao VARCHAR(80) NULL,
  taxa_anual DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT 'rentabilidade estimada a.a. (%) usada na projecao',
  indexador VARCHAR(10) NOT NULL DEFAULT 'manual' COMMENT 'manual | cdi | pre | poupanca',
  percentual_cdi DECIMAL(7,2) NOT NULL DEFAULT 100 COMMENT '% do CDI quando indexador = cdi',
  tributacao VARCHAR(14) NOT NULL DEFAULT 'nenhuma' COMMENT 'ir_regressivo | isento | nenhuma',
  data_vencimento DATE NULL,
  liquidez VARCHAR(12) NOT NULL DEFAULT 'diaria' COMMENT 'diaria | vencimento',
  moeda CHAR(3) NOT NULL DEFAULT 'BRL' COMMENT 'BRL | USD (exposicao ao dolar: exterior ou atrelado ao dolar)',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_inv_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS investimento_movs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  investimento_id INT UNSIGNED NOT NULL,
  data DATE NOT NULL,
  tipo ENUM('aporte','resgate','rendimento','reajuste') NOT NULL,
  valor DECIMAL(14,2) NOT NULL COMMENT 'resgate = valor bruto; reajuste pode ser negativo',
  ir DECIMAL(14,2) NOT NULL DEFAULT 0,
  iof DECIMAL(14,2) NOT NULL DEFAULT 0,
  lancamento_id INT UNSIGNED NULL,
  obs VARCHAR(160) NULL,
  KEY ix_mov_inv (investimento_id, data),
  CONSTRAINT fk_mov_inv FOREIGN KEY (investimento_id) REFERENCES investimentos(id) ON DELETE CASCADE,
  CONSTRAINT fk_mov_lanc FOREIGN KEY (lancamento_id) REFERENCES lancamentos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- indices economicos (Banco Central/SGS): 12 = CDI diario (% ao dia), 4389 = CDI anualizado base 252 (% a.a.), 432 = Selic meta (% a.a.)
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

-- Web Push: um registro por aparelho/navegador (endpoint) do usuario. O hash do endpoint garante um registro por aparelho.
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

-- Cenario economico e risco do patrimonio (v1.16.0): manchetes e leituras do cenario sao globais (como indices); a foto do risco e por perfil.
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
