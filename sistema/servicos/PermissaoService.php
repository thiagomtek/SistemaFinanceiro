<?php
declare(strict_types=1);

/* =========================================================================
   PermissaoService: usuarios, papeis e permissoes por tela (modulo).

   - Usuarios criados por um administrador compartilham os DADOS dele (mesmo perfil financeiro): usuarios.dono_id.
   - Cada modulo tem um nivel: nenhum | ver | editar.
   - Papeis: admin (tudo, inclusive usuarios), editor (edita tudo, menos usuarios), leitor (so ve; sem importacoes e usuarios),
     personalizado (matriz escolhida por modulo).
   - A checagem e central (auth_check.php): pagina -> modulo; POST exige "editar".
   - ADMINISTRADOR UNICO (RN-50): so o usuario com usuarios.sistema_admin = 1 e administrador do sistema. Qualquer outro usuario, mesmo
     com papel "admin" no banco, e tratado como editor e nunca acessa o modulo "usuarios" nem os gatilhos de recurso do servidor.
   ========================================================================= */

const MODULOS = [
    'dashboard' => 'Dashboard', 'projecao' => 'Projeção', 'lancamentos' => 'Lançamentos', 'contas' => 'Contas',
    'cartoes' => 'Cartões e faturas', 'categorias' => 'Categorias', 'investimentos' => 'Investimentos',
    'importacoes' => 'Importar fatura e extrato', 'usuarios' => 'Usuários e permissões',
];
const PAPEIS = ['admin' => 'Administrador', 'editor' => 'Editor', 'leitor' => 'Leitor', 'personalizado' => 'Personalizado'];
const NIVEIS = ['nenhum' => 'Sem acesso', 'ver' => 'Só ver', 'editar' => 'Ver e editar'];
/** Arquivo da tela => modulo. Telas fora desta lista (perfil, logout, notificacoes) exigem apenas login. */
const PAGINA_MODULO = [
    'index.php' => 'dashboard', 'projecao.php' => 'projecao', 'api_projecao.php' => 'projecao', 'api_projecao_risco.php' => 'projecao',
    'cenario.php' => 'projecao', 'api_cenario.php' => 'projecao', 'api_cenario_atualizar.php' => 'projecao', 'api_cenario_perfil.php' => 'projecao',
    'lancamentos.php' => 'lancamentos', 'anexo.php' => 'lancamentos', 'contas.php' => 'contas',
    'cartoes.php' => 'cartoes', 'fatura.php' => 'cartoes', 'categorias.php' => 'categorias',
    'investimentos.php' => 'investimentos', 'api_resgate_previa.php' => 'investimentos', 'api_investimento_painel.php' => 'investimentos',
    'importar_fatura.php' => 'importacoes', 'importar_extrato.php' => 'importacoes', 'usuarios.php' => 'usuarios',
];

/** Matriz modulo => nivel de um papel (para "personalizado", a matriz vem do banco). */
function permissoes_do_papel(string $papel): array
{
    $m = [];
    foreach (array_keys(MODULOS) as $mod) {
        if ($papel === 'admin') {
            $m[$mod] = 'editar';
        } elseif ($papel === 'editor') {
            $m[$mod] = $mod === 'usuarios' ? 'nenhum' : 'editar';
        } elseif ($papel === 'leitor') {
            $m[$mod] = in_array($mod, ['usuarios', 'importacoes'], true) ? 'nenhum' : 'ver';
        } else {
            $m[$mod] = 'nenhum';
        }
    }
    return $m;
}

/** Matriz efetiva de um usuario. */
function permissoes_do_usuario(int $uid): array
{
    $u = q_one('SELECT papel, ativo, sistema_admin, aprovacao FROM usuarios WHERE id = ?', [$uid]);
    if (!$u || !(int) $u['ativo'] || ($u['aprovacao'] ?? 'aprovado') !== 'aprovado') {
        return permissoes_do_papel('nenhum');
    }
    $admin = (int) $u['sistema_admin'] === 1;
    $papel = $admin ? 'admin' : ((string) $u['papel'] === 'admin' ? 'editor' : (string) $u['papel']); // so o administrador do sistema e admin
    $m = permissoes_do_papel($papel);
    if ($papel === 'personalizado') {
        foreach (q_all('SELECT modulo, nivel FROM usuario_permissoes WHERE usuario_id = ?', [$uid]) as $r) {
            if (isset($m[$r['modulo']]) && isset(NIVEIS[$r['nivel']])) {
                $m[$r['modulo']] = $r['nivel'];
            }
        }
    }
    if (!$admin) {
        $m['usuarios'] = 'nenhum'; // gestao de usuarios e exclusiva do administrador do sistema
    }
    return $m;
}

/** O usuario (padrao: o logado) e o administrador do sistema? Unico com privilegio administrativo. */
function usuario_admin_sistema(?int $uid = null): bool
{
    $uid = $uid ?? (int) usuario_id();
    return $uid > 0 && (int) q_val('SELECT sistema_admin FROM usuarios WHERE id = ? AND ativo = 1', [$uid]) === 1;
}

/** Exige o administrador do sistema: JSON 403 em API, redirecionamento com aviso em tela. */
function admin_sistema_exigir(bool $api = false): void
{
    if (usuario_admin_sistema()) {
        return;
    }
    if ($api) {
        json_out(['erro' => 'Somente o administrador do sistema pode fazer isso.'], 403);
    }
    flash('Somente o administrador do sistema pode fazer isso.', 'danger');
    redirect(pagina_inicial_permitida());
}

function permissao_nivel(string $modulo): string
{
    static $cache = [];
    $uid = (int) usuario_id();
    if (!isset($cache[$uid])) {
        $cache[$uid] = permissoes_do_usuario($uid);
    }
    return $cache[$uid][$modulo] ?? 'nenhum';
}

function permissao_pode(string $modulo, string $nivel = 'ver'): bool
{
    $n = permissao_nivel($modulo);
    return $nivel === 'ver' ? in_array($n, ['ver', 'editar'], true) : $n === 'editar';
}

/** Primeira tela que o usuario pode abrir (para redirecionar quem nao tem acesso a pagina pedida). */
function pagina_inicial_permitida(): string
{
    foreach (['index.php' => 'dashboard', 'lancamentos.php' => 'lancamentos', 'contas.php' => 'contas', 'cartoes.php' => 'cartoes', 'projecao.php' => 'projecao',
        'investimentos.php' => 'investimentos', 'categorias.php' => 'categorias', 'usuarios.php' => 'usuarios'] as $arq => $mod) {
        if (permissao_pode($mod, 'ver')) {
            return $arq;
        }
    }
    return 'perfil.php';
}

/** Chamada por auth_check.php: bloqueia tela sem acesso e gravacao sem permissao de edicao. */
function permissao_exigir_pagina(bool $api): void
{
    $arq = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $mod = PAGINA_MODULO[$arq] ?? null;
    if ($mod === null) {
        return;
    }
    $nivel = permissao_nivel($mod);
    $escrita = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($nivel === 'nenhum') {
        if ($api) {
            json_out(['erro' => 'Sem permissão para este recurso.'], 403);
        }
        flash('Você não tem acesso à tela "' . MODULOS[$mod] . '".', 'danger');
        $destino = pagina_inicial_permitida();
        if ($destino === $arq) {
            $destino = 'perfil.php';
        }
        redirect($destino);
    }
    if ($escrita && $nivel !== 'editar') {
        if ($api) {
            json_out(['erro' => 'Sem permissão para alterar.'], 403);
        }
        flash('Você só pode visualizar "' . MODULOS[$mod] . '": alterações não são permitidas.', 'danger');
        redirect($arq);
    }
}

/** Administradores ativos (menos $exceto). Com $donoId, so os do mesmo espaco de dados (dono_id); sem ele, todos. */
function admins_ativos(?int $exceto = null, ?int $donoId = null): int
{
    $sql = "SELECT COUNT(*) FROM usuarios WHERE papel = 'admin' AND ativo = 1 AND id <> ?";
    $par = [(int) $exceto];
    if ($donoId !== null) {
        $sql .= ' AND (dono_id = ? OR (dono_id IS NULL AND id = ?))';
        array_push($par, $donoId, $donoId);
    }
    return (int) q_val($sql, $par);
}

/** Id do dono dos dados (quem criou o espaco) do usuario. */
function dono_dos_dados(int $uid): int
{
    return (int) (q_val('SELECT COALESCE(dono_id, id) FROM usuarios WHERE id = ?', [$uid]) ?: $uid);
}

/**
 * Cria ou atualiza um usuario do mesmo espaco de dados do administrador $donoId.
 * $d: nome, email, senha (obrigatoria ao criar), papel, ativo, permissoes (modulo => nivel; so "personalizado").
 */
function usuario_salvar(array $d, int $id, int $donoId): int
{
    $nome = trim((string) ($d['nome'] ?? ''));
    $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
    $senha = (string) ($d['senha'] ?? '');
    $papel = isset(PAPEIS[$d['papel'] ?? '']) ? (string) $d['papel'] : 'leitor';
    $ativo = !isset($d['ativo']) || !empty($d['ativo']) ? 1 : 0;
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Informe nome e um e-mail válido.');
    }
    if ($papel === 'admin' && !usuario_admin_sistema($id)) {
        throw new InvalidArgumentException('Somente o administrador do sistema pode ter o papel de administrador.');
    }
    if ($id === 0 && strlen($senha) < 8) {
        throw new InvalidArgumentException('A senha deve ter ao menos 8 caracteres.');
    }
    if ($senha !== '' && strlen($senha) < 8) {
        throw new InvalidArgumentException('A senha deve ter ao menos 8 caracteres.');
    }
    if (q_val('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$email, $id])) {
        throw new InvalidArgumentException('Já existe um usuário com esse e-mail.');
    }
    if ($id) {
        $atual = q_one('SELECT * FROM usuarios WHERE id = ? AND (dono_id = ? OR (dono_id IS NULL AND id = ?))', [$id, $donoId, $donoId]);
        if (!$atual) {
            throw new InvalidArgumentException('Usuário não encontrado.');
        }
        if ((int) $atual['sistema_admin'] === 1 && ($papel !== 'admin' || !$ativo)) {
            throw new InvalidArgumentException('O administrador do sistema não pode perder o papel nem ser inativado.');
        }
        // nao deixa o sistema sem administrador ativo
        $viraraNaoAdmin = $atual['papel'] === 'admin' && (int) $atual['ativo'] === 1 && ($papel !== 'admin' || !$ativo);
        if ($viraraNaoAdmin && admins_ativos($id, $donoId) < 1) {
            throw new InvalidArgumentException('É preciso manter ao menos um administrador ativo.');
        }
        q('UPDATE usuarios SET nome = ?, email = ?, papel = ?, ativo = ? WHERE id = ?', [$nome, $email, $papel, $ativo, $id]);
        if ($senha !== '') {
            q('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $id]);
        }
    } else {
        $id = q_insert('INSERT INTO usuarios (nome, email, senha_hash, papel, ativo, dono_id) VALUES (?,?,?,?,?,?)',
            [$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $papel, $ativo, $donoId]);
    }
    q('DELETE FROM usuario_permissoes WHERE usuario_id = ?', [$id]);
    if ($papel === 'personalizado') {
        foreach (array_keys(MODULOS) as $mod) {
            if ($mod === 'usuarios') {
                continue; // nunca concedido a outro usuario
            }
            $n = (string) ($d['permissoes'][$mod] ?? 'nenhum');
            if (isset(NIVEIS[$n]) && $n !== 'nenhum') {
                q_insert('INSERT INTO usuario_permissoes (usuario_id, modulo, nivel) VALUES (?,?,?)', [$id, $mod, $n]);
            }
        }
    }
    return $id;
}

function usuario_excluir(int $id, int $donoId, int $eu): void
{
    $u = q_one('SELECT * FROM usuarios WHERE id = ? AND (dono_id = ? OR (dono_id IS NULL AND id = ?))', [$id, $donoId, $donoId]);
    if (!$u) {
        throw new InvalidArgumentException('Usuário não encontrado.');
    }
    if ($id === $eu) {
        throw new InvalidArgumentException('Você não pode excluir o próprio usuário.');
    }
    if ((int) $u['sistema_admin'] === 1) {
        throw new InvalidArgumentException('O administrador do sistema não pode ser excluído.');
    }
    if ($u['papel'] === 'admin' && (int) $u['ativo'] === 1 && admins_ativos($id, $donoId) < 1) {
        throw new InvalidArgumentException('É preciso manter ao menos um administrador ativo.');
    }
    if ($id === $donoId) {
        throw new InvalidArgumentException('O usuário dono dos dados não pode ser excluído; inative ou troque o papel.');
    }
    q('DELETE FROM usuarios WHERE id = ?', [$id]);
}

/** Meu perfil: nome e e-mail do proprio usuario. */
function perfil_salvar_dados(int $uid, string $nome, string $email): void
{
    $nome = trim($nome);
    $email = mb_strtolower(trim($email));
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Informe nome e um e-mail válido.');
    }
    if (q_val('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$email, $uid])) {
        throw new InvalidArgumentException('Já existe um usuário com esse e-mail.');
    }
    q('UPDATE usuarios SET nome = ?, email = ? WHERE id = ?', [$nome, $email, $uid]);
}

/** Meu perfil: troca de senha exigindo a senha atual. */
function perfil_trocar_senha(int $uid, string $atual, string $nova, string $confirma): void
{
    $u = q_one('SELECT senha_hash FROM usuarios WHERE id = ?', [$uid]);
    if (!$u || !password_verify($atual, $u['senha_hash'])) {
        throw new InvalidArgumentException('A senha atual está incorreta.');
    }
    if (strlen($nova) < 8) {
        throw new InvalidArgumentException('A nova senha deve ter ao menos 8 caracteres.');
    }
    if ($nova !== $confirma) {
        throw new InvalidArgumentException('A confirmação não confere com a nova senha.');
    }
    q('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $uid]);
}

// ------------------------------------------------------------------ tentativas de login

/** Hash de uma senha aleatoria descartada: conferido quando o e-mail nao existe, para o tempo de resposta ser igual. */
const LOGIN_HASH_FALSO = '$2y$10$PBQClfYL1wizUJhYqemX.uIHGLHK1SP0fJ0YZObbnGVtOE4jHhQ2m';
const LOGIN_MAX_FALHAS = 5;
const LOGIN_JANELA_S = 900;

/** Chave da tentativa: e-mail + IP do cliente (o Caddy troca o X-Forwarded-For, entao o primeiro valor e o do cliente). */
function login_chave(string $email): string
{
    return 'lf:' . sha1(mb_strtolower($email) . '|' . cliente_ip());
}

/** Segundos que faltam para poder tentar de novo (0 = liberado). */
function login_bloqueado_por(string $email, ?int $agora = null): int
{
    $agora = $agora ?? time();
    $v = parametro_get(login_chave($email));
    if ($v === null) {
        return 0;
    }
    [$ts, $n] = array_map('intval', explode('|', $v) + [0, 0]);
    if ($agora - $ts >= LOGIN_JANELA_S) {
        return 0;
    }
    return $n >= LOGIN_MAX_FALHAS ? LOGIN_JANELA_S - ($agora - $ts) : 0;
}

function login_registrar_falha(string $email, ?int $agora = null): void
{
    $agora = $agora ?? time();
    $chave = login_chave($email);
    $v = parametro_get($chave);
    $n = 1;
    $ts = $agora;
    if ($v !== null) {
        [$ts0, $n0] = array_map('intval', explode('|', $v) + [0, 0]);
        if ($agora - $ts0 < LOGIN_JANELA_S) {
            $ts = $ts0; // a janela conta a partir da primeira falha
            $n = $n0 + 1;
        }
    }
    parametro_set($chave, $ts . '|' . $n);
    // limpa registros fora da janela (conta em PHP: um CAST no SQL falharia no modo estrito do MariaDB com valores sem '|')
    foreach (q_all("SELECT chave, valor FROM parametros WHERE chave LIKE 'lf:%'") as $r) {
        if ($agora - (int) explode('|', (string) $r['valor'])[0] >= LOGIN_JANELA_S) {
            q('DELETE FROM parametros WHERE chave = ?', [$r['chave']]);
        }
    }
}

function login_limpar_falhas(string $email): void
{
    q('DELETE FROM parametros WHERE chave = ?', [login_chave($email)]);
}

const LOGIN_MAX_FALHAS_IP = 30; // falhas de qualquer e-mail vindas do mesmo IP na janela (barra a "pulverizacao" de senhas)

/**
 * Autentica e-mail e senha. Devolve ['ok' => true, 'usuario' => linha] ou ['ok' => false, 'motivo' => bloqueado|pendente|incorreto, 'espera' => s].
 * Bloqueia por e-mail+IP (5 falhas) e por IP (30 falhas) em 15 minutos; o tempo de resposta e igual com e sem conta; conta recusada ou
 * inativa responde como senha incorreta; so uma conta pendente com senha certa e avisada (nao e contada como falha).
 */
function login_autenticar(string $email, string $senha): array
{
    $email = mb_strtolower(trim($email));
    $ip = cliente_ip();
    $espera = max(login_bloqueado_por($email), limite_bloqueado_por('login-ip:' . $ip, LOGIN_MAX_FALHAS_IP, LOGIN_JANELA_S));
    if ($espera > 0) {
        return ['ok' => false, 'motivo' => 'bloqueado', 'espera' => $espera];
    }
    $u = $email !== '' ? q_one('SELECT * FROM usuarios WHERE email = ?', [$email]) : null;
    usleep(300000); // atraso fixo e verificacao mesmo sem usuario
    $senhaOk = password_verify($senha, $u ? (string) $u['senha_hash'] : LOGIN_HASH_FALSO) && $u;
    if ($senhaOk && (int) $u['ativo'] === 1 && ($u['aprovacao'] ?? 'aprovado') === 'aprovado') {
        login_limpar_falhas($email);
        return ['ok' => true, 'usuario' => $u];
    }
    if ($senhaOk && ($u['aprovacao'] ?? '') === 'pendente') {
        return ['ok' => false, 'motivo' => 'pendente', 'espera' => 0];
    }
    login_registrar_falha($email);
    limite_consumir('login-ip:' . $ip, LOGIN_MAX_FALHAS_IP, LOGIN_JANELA_S);
    return ['ok' => false, 'motivo' => 'incorreto', 'espera' => 0];
}
