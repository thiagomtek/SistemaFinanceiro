<?php
$API_JSON = true;
require_once __DIR__ . '/auth_check.php';

// Aparelhos do usuario para notificacao push. GET = estado e chave publica; POST (CSRF) = assinar | cancelar | teste.
$uid = (int) usuario_id();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['disponivel' => push_disponivel(), 'chave' => push_chave_publica(), 'dispositivos' => push_dispositivos($uid)]);
}
csrf_exigir();
$corpo = json_decode((string) file_get_contents('php://input'), true);
$corpo = is_array($corpo) ? $corpo : $_POST;
$acao = (string) ($corpo['acao'] ?? '');
try {
    if (!push_disponivel()) {
        throw new InvalidArgumentException('As notificações push não estão configuradas no servidor.');
    }
    if ($acao === 'assinar') {
        push_registrar($uid, is_array($corpo['assinatura'] ?? null) ? $corpo['assinatura'] : [], (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    } elseif ($acao === 'cancelar') {
        push_cancelar($uid, (string) ($corpo['endpoint'] ?? ''));
    } elseif ($acao === 'teste') {
        // no maximo 1 teste a cada 15 s por sessao
        if (($_SESSION['push_teste'] ?? 0) > time() - 15) {
            throw new InvalidArgumentException('Aguarde alguns segundos para enviar outro teste.');
        }
        if (!limite_consumir('uso:push_teste:' . $uid, 10, 3600)) {
            throw new InvalidArgumentException('Limite de testes de notificação por hora atingido.');
        }
        $_SESSION['push_teste'] = time();
        $r = push_enviar_usuario($uid, ['titulo' => 'Notificação de teste', 'mensagem' => 'Está tudo certo: este aparelho recebe os avisos do Sistema Financeiro.', 'url' => 'perfil.php', 'chave' => 'teste']);
        json_out(['ok' => $r['enviadas'] > 0, 'enviadas' => $r['enviadas'], 'falhas' => $r['falhas'], 'dispositivos' => push_dispositivos($uid)]);
    } else {
        throw new InvalidArgumentException('Ação inválida.');
    }
    json_out(['ok' => true, 'dispositivos' => push_dispositivos($uid)]);
} catch (InvalidArgumentException $e) {
    json_out(['erro' => $e->getMessage()], 422);
}
