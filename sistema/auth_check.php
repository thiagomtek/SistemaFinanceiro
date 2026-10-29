<?php
declare(strict_types=1);

/*
 * Guarda de autenticacao (mesmo papel do auth_check.php do ERP). Inclua no topo de toda pagina:
 *   require_once __DIR__ . '/auth_check.php';
 * Antes do include: $PAGINA_PUBLICA = true (login/logout) ou $API_JSON = true (endpoints api_*.php, responde 401 em JSON).
 */

require_once __DIR__ . '/bootstrap.php';

if (empty($PAGINA_PUBLICA)) {
    if (!empty($API_JSON)) {
        exigir_login_api();
    } else {
        exigir_login();
    }
}

// permissoes por tela (usuario inativo perde o acesso na hora)
if (empty($PAGINA_PUBLICA)) {
    if (!(int) q_val('SELECT ativo FROM usuarios WHERE id = ?', [(int) usuario_id()])) {
        unset($_SESSION['usuario_id'], $_SESSION['perfil_id']);
        if (!empty($API_JSON)) {
            json_out(['erro' => 'Sessão expirada.'], 401);
        }
        redirect('login.php');
    }
    permissao_exigir_pagina(!empty($API_JSON));
}

// receitas/despesas fixas sao sem fim: mantem as proximas ocorrencias geradas (no maximo uma checagem por hora por sessao;
// criar uma serie ja gera ate o horizonte e a projecao estende por conta propria)
if (empty($PAGINA_PUBLICA) && empty($API_JSON) && (time() - (int) ($_SESSION['rec_checado_em'] ?? 0)) > 3600) {
    try {
        recorrencias_estender();
        $_SESSION['rec_checado_em'] = time();
    } catch (Throwable $e) {
        // nunca impede o uso do sistema
    }
}
