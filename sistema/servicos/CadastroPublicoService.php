<?php
declare(strict_types=1);

/* =========================================================================
   CadastroPublicoService: cadastro de novos usuarios na tela de entrada (RN-51).
   - Modos (definidos so pelo administrador do sistema): fechado | aprovacao (padrao) | aberto.
   - Cada cadastro e um espaco de dados proprio (dono_id nulo): nunca enxerga dados de outra pessoa.
   - A conta nasce SEM privilegio administrativo, sem o modulo de usuarios e, por padrao, sem importacoes por IA (custo no servidor).
   - Resposta igual para e-mail novo e ja cadastrado (nao revela quem tem conta); a senha e sempre processada (mesmo tempo).
   - Defesas: CSRF, campo isca (honeypot), tempo minimo de preenchimento, limite por IP (hora e dia) e teto diario global.
   ========================================================================= */

const CADASTRO_MODOS = ['fechado' => 'Fechado (ninguém se cadastra)', 'aprovacao' => 'Com aprovação do administrador', 'aberto' => 'Aberto (acesso imediato)'];
const CADASTRO_LIMITE_DIA_PADRAO = 20;
const CADASTRO_IP_POR_HORA = 3;
const CADASTRO_IP_POR_DIA = 8;
const CADASTRO_IP_TENTATIVAS_HORA = 30; // qualquer envio (inclusive com erro de digitacao) por IP: limite folgado para pessoas e firme para robos
const CADASTRO_TEMPO_MIN_S = 3;
const CADASTRO_TEMPO_MAX_S = 7200;
const CADASTRO_SENHA_MIN = 10;
const CADASTRO_SENHAS_FRACAS = ['1234567890', 'password12', 'senha12345', '12345678910', 'qwertyuiop', '1q2w3e4r5t', 'abcdefghij', '0123456789', 'senhasenha', 'password123',
    'senha123456', 'admin12345', 'mudar12345', 'brasil1234', '123456789a', 'abc1234567', 'minhasenha', 'senhaforte', 'passw0rd123', 'qwerty12345'];
/** Modulos liberados a quem se cadastra (importacoes entra so se o administrador permitir). */
const CADASTRO_MODULOS_PADRAO = ['dashboard', 'projecao', 'lancamentos', 'contas', 'cartoes', 'categorias', 'investimentos'];

function cadastro_modo(): string
{
    $m = parametro_get('cadastro_modo');
    return isset(CADASTRO_MODOS[$m ?? '']) ? (string) $m : 'aprovacao';
}

function cadastro_limite_dia(): int
{
    return max(1, min(500, (int) (parametro_get('cadastro_limite_dia') ?? CADASTRO_LIMITE_DIA_PADRAO)));
}

function cadastro_exigir_admin(): void
{
    if (!usuario_admin_sistema()) {
        throw new InvalidArgumentException('Somente o administrador do sistema pode fazer isso.');
    }
}

function cadastro_config_salvar(string $modo, int $limiteDia): void
{
    cadastro_exigir_admin();
    if (!isset(CADASTRO_MODOS[$modo])) {
        throw new InvalidArgumentException('Modo de cadastro inválido.');
    }
    if ($limiteDia < 1 || $limiteDia > 500) {
        throw new InvalidArgumentException('O limite diário de cadastros deve ficar entre 1 e 500.');
    }
    parametro_set('cadastro_modo', $modo);
    parametro_set('cadastro_limite_dia', (string) $limiteDia);
}

/** Valida a senha de um cadastro publico. @throws InvalidArgumentException */
function cadastro_senha_valida(string $senha, string $nome, string $email): void
{
    if (strlen($senha) < CADASTRO_SENHA_MIN) {
        throw new InvalidArgumentException('A senha deve ter ao menos ' . CADASTRO_SENHA_MIN . ' caracteres.');
    }
    if (strlen($senha) > 72) {
        throw new InvalidArgumentException('A senha deve ter no máximo 72 caracteres.');
    }
    $s = mb_strtolower($senha);
    $local = mb_strtolower((string) strstr($email, '@', true));
    if (in_array($s, CADASTRO_SENHAS_FRACAS, true) || count(array_unique(preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY))) < 5 || preg_match('/^(.{1,3})\1+$/u', $s)) {
        throw new InvalidArgumentException('Essa senha é fraca demais. Use uma senha longa e pouco previsível.');
    }
    if (mb_strlen($local) >= 4 && mb_strpos($s, $local) !== false) {
        throw new InvalidArgumentException('A senha não pode conter o seu e-mail.');
    }
    foreach (preg_split('/\s+/', mb_strtolower($nome)) as $parte) {
        if (mb_strlen($parte) >= 4 && mb_strpos($s, $parte) !== false) {
            throw new InvalidArgumentException('A senha não pode conter o seu nome.');
        }
    }
}

/** Permissoes padrao de quem se cadastra: tudo dos dados dele, sem usuarios e (por padrao) sem importacoes. */
function cadastro_permissoes_padrao(int $uid, bool $importacoes): void
{
    q('DELETE FROM usuario_permissoes WHERE usuario_id = ?', [$uid]);
    foreach (array_merge(CADASTRO_MODULOS_PADRAO, $importacoes ? ['importacoes'] : []) as $mod) {
        q_insert('INSERT INTO usuario_permissoes (usuario_id, modulo, nivel) VALUES (?,?,?)', [$uid, $mod, 'editar']);
    }
}

/**
 * Registra um cadastro. Devolve sempre a mesma mensagem para e-mail novo e ja existente.
 * @param array $in nome, email, senha, website (isca), inicio (instante em que o formulario foi exibido)
 * @throws InvalidArgumentException com a mensagem a mostrar (campos invalidos, limite ou cadastro fechado)
 */
function cadastro_publico_registrar(array $in): string
{
    $modo = cadastro_modo();
    if ($modo === 'fechado') {
        throw new InvalidArgumentException('O cadastro de novos usuários está fechado no momento.');
    }
    $mensagem = $modo === 'aberto'
        ? 'Cadastro recebido. Se esse e-mail ainda não tinha conta, ela foi criada e você já pode entrar.'
        : 'Cadastro recebido. O acesso será liberado depois da aprovação do administrador.';
    if (trim((string) ($in['website'] ?? '')) !== '') {
        usleep(400000);
        return $mensagem; // campo isca preenchido: robo; finge sucesso e nao cria nada
    }
    $tempo = time() - (int) ($in['inicio'] ?? 0);
    if ($tempo < CADASTRO_TEMPO_MIN_S || $tempo > CADASTRO_TEMPO_MAX_S) {
        throw new InvalidArgumentException('O formulário expirou ou foi enviado rápido demais. Recarregue a página e tente de novo.');
    }
    $ip = cliente_ip();
    if (!limite_consumir('cad:ip-tent:' . $ip, CADASTRO_IP_TENTATIVAS_HORA, 3600)) {
        throw new InvalidArgumentException('Muitas tentativas deste endereço. Tente de novo mais tarde.');
    }
    $nome = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['nome'] ?? '')));
    $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
    $senha = (string) ($in['senha'] ?? '');
    if (!preg_match("/^[\p{L}][\p{L}\p{M} .'-]{1,79}$/u", $nome)) {
        throw new InvalidArgumentException('Informe o nome (2 a 80 letras).');
    }
    if (strlen($email) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Informe um e-mail válido.');
    }
    cadastro_senha_valida($senha, $nome, $email);
    // o limite firme (3 por hora, 8 por dia) conta so envios validos: erro de digitacao nao gasta a cota de quem esta se cadastrando
    if (!limite_consumir('cad:ip-h:' . $ip, CADASTRO_IP_POR_HORA, 3600) || !limite_consumir('cad:ip-d:' . $ip, CADASTRO_IP_POR_DIA, 86400)) {
        throw new InvalidArgumentException('Muitas tentativas deste endereço. Tente de novo mais tarde.');
    }
    $hash = password_hash($senha, PASSWORD_DEFAULT); // sempre, para o tempo nao revelar se o e-mail ja existe
    if (!limite_consumir('cad:dia:' . date('Y-m-d'), cadastro_limite_dia(), 86400)) {
        throw new InvalidArgumentException('O limite de cadastros de hoje foi atingido. Tente amanhã.');
    }
    if (q_val('SELECT id FROM usuarios WHERE email = ?', [$email])) {
        return $mensagem;
    }
    try {
        $id = q_insert(
            'INSERT INTO usuarios (nome, email, senha_hash, papel, ativo, dono_id, sistema_admin, cadastro, aprovacao, aprovado_em) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$nome, $email, $hash, 'personalizado', $modo === 'aberto' ? 1 : 0, null, 0, 'publico', $modo === 'aberto' ? 'aprovado' : 'pendente', $modo === 'aberto' ? date('Y-m-d H:i:s') : null]
        );
    } catch (PDOException $e) {
        return $mensagem; // corrida: o e-mail foi cadastrado no mesmo instante
    }
    cadastro_permissoes_padrao($id, false);
    return $mensagem;
}

// ------------------------------------------------------ gestao (so o administrador do sistema)

/** Cadastros publicos para a tela do administrador. Sem nenhum dado financeiro. */
function cadastro_lista(string $aprovacao): array
{
    cadastro_exigir_admin();
    return q_all("SELECT id, nome, email, ativo, aprovacao, criado_em, aprovado_em, ultimo_acesso FROM usuarios WHERE cadastro = 'publico' AND aprovacao = ? ORDER BY id DESC LIMIT 200", [$aprovacao]);
}

function cadastro_importacoes_liberada(int $uid): bool
{
    return (bool) q_val("SELECT 1 FROM usuario_permissoes WHERE usuario_id = ? AND modulo = 'importacoes' AND nivel = 'editar'", [$uid]);
}

function cadastro_buscar_publico(int $id, array $aprovacoes): array
{
    cadastro_exigir_admin();
    $marcas = implode(',', array_fill(0, count($aprovacoes), '?'));
    $u = q_one("SELECT * FROM usuarios WHERE id = ? AND cadastro = 'publico' AND sistema_admin = 0 AND aprovacao IN ($marcas)", array_merge([$id], $aprovacoes));
    if (!$u) {
        throw new InvalidArgumentException('Cadastro não encontrado.');
    }
    return $u;
}

function cadastro_aprovar(int $id, bool $importacoes): void
{
    cadastro_buscar_publico($id, ['pendente']);
    q("UPDATE usuarios SET ativo = 1, aprovacao = 'aprovado', aprovado_em = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
    cadastro_permissoes_padrao($id, $importacoes);
}

function cadastro_recusar(int $id): void
{
    cadastro_buscar_publico($id, ['pendente']);
    q("UPDATE usuarios SET ativo = 0, aprovacao = 'recusado' WHERE id = ?", [$id]);
}

/** Exclui um cadastro pendente ou recusado que nunca entrou (nao tem dados). */
function cadastro_excluir(int $id): void
{
    $u = cadastro_buscar_publico($id, ['pendente', 'recusado']);
    if ($u['ultimo_acesso'] !== null) {
        throw new InvalidArgumentException('Esse cadastro já acessou o sistema e não pode ser excluído aqui.');
    }
    q('DELETE FROM usuarios WHERE id = ?', [$id]);
}

/** Inativa ou reativa um cadastro ja aprovado (sem apagar nada). */
function cadastro_alternar_ativo(int $id): void
{
    $u = cadastro_buscar_publico($id, ['aprovado']);
    q('UPDATE usuarios SET ativo = ? WHERE id = ?', [(int) $u['ativo'] === 1 ? 0 : 1, $id]);
}

function cadastro_definir_importacoes(int $id, bool $liberar): void
{
    cadastro_buscar_publico($id, ['aprovado']);
    cadastro_permissoes_padrao($id, $liberar);
}
