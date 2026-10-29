<?php
declare(strict_types=1);

/* =========================================================================
   PushService: Web Push (aviso no celular/navegador com o aplicativo fechado).
   Mesma biblioteca e versao do ERP (minishlink/web-push), mas totalmente separado dele: chaves VAPID proprias (variaveis
   PUSH_VAPID_*), tabela push_assinaturas no banco do Financeiro e origem propria (porta 8453), entao as assinaturas dos
   dois sistemas nunca se misturam. Sem as chaves ou sem a biblioteca, o push fica desligado e o sino/aviso local seguem iguais.
   ========================================================================= */

/** Servicos de push dos navegadores. O servidor so envia para enderecos destes dominios (evita usar o sistema para chamar outros enderecos). */
const PUSH_HOSTS_PERMITIDOS = ['googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

/** Carrega o autoload do Composer (fora do DocumentRoot na imagem: /var/www/vendor). */
function push_autoload(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = class_exists('Minishlink\\WebPush\\WebPush', false);
        foreach (array_filter([(string) getenv('PUSH_VENDOR_DIR'), APP_ROOT . '/vendor', '/var/www/vendor']) as $dir) {
            if ($ok) {
                break;
            }
            if (is_file($dir . '/autoload.php')) {
                require_once $dir . '/autoload.php';
                $ok = class_exists('Minishlink\\WebPush\\WebPush');
            }
        }
    }
    return $ok;
}

/** @return array{public: string, private: string, subject: string} */
function push_vapid(): array
{
    return ['public' => trim((string) getenv('PUSH_VAPID_PUBLIC')), 'private' => trim((string) getenv('PUSH_VAPID_PRIVATE')), 'subject' => trim((string) getenv('PUSH_VAPID_SUBJECT'))];
}

function push_disponivel(): bool
{
    if (isset($GLOBALS['FIN_PUSH_MOCK'])) {
        return true;
    }
    $v = push_vapid();
    return $v['public'] !== '' && $v['private'] !== '' && $v['subject'] !== '' && push_autoload();
}

function push_chave_publica(): string
{
    return push_vapid()['public'];
}

/** Endereco de assinatura aceito: https, sem usuario/porta, de um dos servicos de push conhecidos. */
function push_endpoint_valido(string $url): bool
{
    if ($url === '' || strlen($url) > 1000) {
        return false;
    }
    $p = parse_url($url);
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
        return false;
    }
    if (isset($p['port']) && (int) $p['port'] !== 443) {
        return false;
    }
    $host = strtolower((string) $p['host']);
    foreach (PUSH_HOSTS_PERMITIDOS as $d) {
        if ($host === $d || substr($host, -(strlen($d) + 1)) === '.' . $d) {
            return true;
        }
    }
    return false;
}

/** Grava (ou atualiza) o aparelho do usuario. $s = PushSubscription.toJSON() do navegador. O aparelho passa a ser de quem entrou por ultimo. */
function push_registrar(int $uid, array $s, string $userAgent = ''): void
{
    $endpoint = trim((string) ($s['endpoint'] ?? ''));
    $keys = is_array($s['keys'] ?? null) ? $s['keys'] : [];
    $p256dh = trim((string) ($keys['p256dh'] ?? ''));
    $auth = trim((string) ($keys['auth'] ?? ''));
    if (!push_endpoint_valido($endpoint)) {
        throw new InvalidArgumentException('Endereço de notificação não aceito.');
    }
    if (!preg_match('/^[A-Za-z0-9_\-]{86,90}={0,2}$/', $p256dh) || !preg_match('/^[A-Za-z0-9_\-]{20,26}={0,2}$/', $auth)) {
        throw new InvalidArgumentException('Chaves da assinatura inválidas.');
    }
    $hash = hash('sha256', $endpoint);
    $agora = date('Y-m-d H:i:s');
    $ua = mb_substr($userAgent, 0, 255);
    if (q_val('SELECT id FROM push_assinaturas WHERE endpoint_hash = ?', [$hash])) {
        q('UPDATE push_assinaturas SET usuario_id = ?, p256dh = ?, auth = ?, user_agent = ?, atualizada_em = ? WHERE endpoint_hash = ?', [$uid, $p256dh, $auth, $ua, $agora, $hash]);
    } else {
        q_insert('INSERT INTO push_assinaturas (usuario_id, endpoint, endpoint_hash, p256dh, auth, user_agent, atualizada_em) VALUES (?,?,?,?,?,?,?)', [$uid, $endpoint, $hash, $p256dh, $auth, $ua, $agora]);
    }
}

/** Remove o aparelho do usuario (so o dele). */
function push_cancelar(int $uid, string $endpoint): void
{
    q('DELETE FROM push_assinaturas WHERE usuario_id = ? AND endpoint_hash = ?', [$uid, hash('sha256', trim($endpoint))]);
}

function push_dispositivos(int $uid): int
{
    return (int) q_val('SELECT COUNT(*) FROM push_assinaturas WHERE usuario_id = ?', [$uid]);
}

/** Destino do clique: so uma tela do sistema (relativa a /sistema/); qualquer outra coisa vira o inicio. */
function push_url_relativa(string $url): string
{
    $p = parse_url(trim($url));
    if (!is_array($p) || isset($p['scheme']) || isset($p['host'])) {
        return 'index.php';
    }
    $caminho = ltrim((string) ($p['path'] ?? ''), '/');
    if (!preg_match('/^[A-Za-z0-9_]+\.php$/', $caminho)) {
        return 'index.php';
    }
    return $caminho . (isset($p['query']) ? '?' . $p['query'] : '');
}

/** Corpo da notificacao. O titulo leva o prefixo "Financeiro" para nao se confundir com os avisos do ERP no mesmo aparelho. */
function push_payload(array $n): string
{
    $titulo = trim((string) ($n['titulo'] ?? ''));
    if (stripos($titulo, 'financeiro') === false) {
        $titulo = 'Financeiro · ' . $titulo;
    }
    $chave = substr((string) preg_replace('/[^A-Za-z0-9:_\-]/', '', (string) ($n['chave'] ?? 'aviso')), 0, 60);
    return (string) json_encode([
        'titulo' => mb_substr($titulo, 0, 120),
        'mensagem' => mb_substr((string) ($n['mensagem'] ?? ''), 0, 255),
        'url' => push_url_relativa((string) ($n['url'] ?? '')),
        'tag' => 'fin-' . ($chave !== '' ? $chave : 'aviso'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Entrega o corpo as assinaturas. @return array<int, array{endpoint: string, ok: bool, expirada: bool, motivo: string}>
 * Nos testes, $GLOBALS['FIN_PUSH_MOCK'] = function (array $assinaturas, string $payload): array substitui o envio de verdade.
 */
function push_despachar(array $subs, string $payload): array
{
    if (isset($GLOBALS['FIN_PUSH_MOCK'])) {
        return ($GLOBALS['FIN_PUSH_MOCK'])($subs, $payload);
    }
    push_autoload();
    $v = push_vapid();
    // TTL de 1 dia: o aviso e do dia; se o aparelho ficar desligado mais que isso, ele perde o sentido
    $web = new \Minishlink\WebPush\WebPush(['VAPID' => ['subject' => $v['subject'], 'publicKey' => $v['public'], 'privateKey' => $v['private']]], ['TTL' => 86400, 'urgency' => 'normal'], 10);
    foreach ($subs as $s) {
        // aes128gcm (RFC 8291) e o formato atual de todos os navegadores e o unico aceito pelo Safari/iOS; o padrao da biblioteca e o antigo aesgcm
        $web->queueNotification(\Minishlink\WebPush\Subscription::create(['endpoint' => $s['endpoint'], 'contentEncoding' => 'aes128gcm', 'keys' => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']]]), $payload);
    }
    $out = [];
    foreach ($web->flush() as $rep) {
        $resp = $rep->getResponse();
        $status = $resp ? (int) $resp->getStatusCode() : 0;
        $out[] = ['endpoint' => $rep->getEndpoint(), 'ok' => $rep->isSuccess(), 'expirada' => $rep->isSubscriptionExpired() || $status === 404 || $status === 410, 'motivo' => (string) $rep->getReason()];
    }
    return $out;
}

/**
 * Envia um aviso a todos os aparelhos do usuario. Nunca lanca: falha so vai para o log. Aparelhos expirados (404/410) sao removidos.
 * $n = {titulo, mensagem, url, chave}. @return array{enviadas: int, falhas: int, removidas: int}
 */
function push_enviar_usuario(int $uid, array $n): array
{
    $r = ['enviadas' => 0, 'falhas' => 0, 'removidas' => 0];
    $subs = q_all('SELECT id, endpoint, p256dh, auth FROM push_assinaturas WHERE usuario_id = ? ORDER BY id', [$uid]);
    if (!$subs || !push_disponivel()) {
        return $r;
    }
    try {
        $reports = push_despachar($subs, push_payload($n));
    } catch (Throwable $e) {
        error_log('[push] falha ao enviar: ' . $e->getMessage());
        $r['falhas'] = count($subs);
        return $r;
    }
    foreach ($reports as $rep) {
        if ($rep['ok']) {
            $r['enviadas']++;
            continue;
        }
        $r['falhas']++;
        if ($rep['expirada']) {
            q('DELETE FROM push_assinaturas WHERE endpoint_hash = ?', [hash('sha256', (string) $rep['endpoint'])]);
            $r['removidas']++;
        } else {
            error_log('[push] aparelho recusou o aviso: ' . $rep['motivo']);
        }
    }
    return $r;
}

/**
 * Rotina diaria (cron_notificacoes.php): para cada usuario ativo com aparelho cadastrado, grava os avisos de hoje que ainda
 * nao existem e os envia por push. Cada aviso vai uma unica vez (a chave e unica por usuario), entao rodar de novo nao repete.
 * @return array{usuarios: int, avisos: int, enviadas: int, falhas: int, removidas: int}
 */
function push_notificar_pendentes(?string $hoje = null): array
{
    $res = ['usuarios' => 0, 'avisos' => 0, 'enviadas' => 0, 'falhas' => 0, 'removidas' => 0];
    if (!push_disponivel()) {
        return $res;
    }
    $guarda = $_SESSION;
    foreach (q_all('SELECT DISTINCT u.id FROM usuarios u INNER JOIN push_assinaturas p ON p.usuario_id = u.id WHERE u.ativo = 1 ORDER BY u.id') as $row) {
        $uid = (int) $row['id'];
        $_SESSION = ['usuario_id' => $uid]; // as regras de aviso e permissao trabalham sobre o usuario da sessao
        garantir_perfil();
        $res['usuarios']++;
        foreach (array_slice(notificacoes_sincronizar_itens($hoje), 0, 5) as $n) {
            $r = push_enviar_usuario($uid, $n);
            $res['avisos']++;
            $res['enviadas'] += $r['enviadas'];
            $res['falhas'] += $r['falhas'];
            $res['removidas'] += $r['removidas'];
        }
    }
    $_SESSION = $guarda;
    return $res;
}
