<?php
declare(strict_types=1);

/* =========================================================================
   NotificacaoService: avisos do dia, mostrados no sino do topo e, se o usuario permitir, como notificacao do navegador.
   - Contas a pagar e a receber que vencem HOJE (e quantas estao atrasadas);
   - No dia seguinte ao fechamento da fatura de um cartao: "a fatura fechou, importe no sistema".
   Cada aviso e gravado uma vez por usuario (chave unica) e fica lido/nao lido.
   ========================================================================= */

/** Avisos que valem hoje. @return array<int, array{chave: string, titulo: string, mensagem: string, url: string}> */
function notificacoes_do_dia(?string $hoje = null): array
{
    $hoje = $hoje ?: date('Y-m-d');
    $ontem = date('Y-m-d', strtotime($hoje . ' -1 day'));
    $out = [];
    $pid = perfil_id();

    if (permissao_pode('lancamentos', 'ver')) {
        foreach (['despesa' => ['Contas a pagar hoje', 'a pagar', 'despesa'], 'receita' => ['Contas a receber hoje', 'a receber', 'receita']] as $tipo => [$tit, $verbo]) {
            $r = q_one(
                "SELECT COUNT(*) AS n, COALESCE(SUM(valor), 0) AS total FROM lancamentos WHERE perfil_id = ? AND tipo = ? AND efetivado = 0 AND cartao_id IS NULL AND data = ?",
                [$pid, $tipo, $hoje]
            );
            if ((int) $r['n'] > 0) {
                $out[] = ['chave' => 'dia:' . $hoje . ':' . $tipo, 'titulo' => $tit,
                    'mensagem' => (int) $r['n'] . ' lançamento(s) ' . $verbo . ' hoje, somando ' . brl((float) $r['total']) . '.',
                    'url' => 'lancamentos.php?mes=' . substr($hoje, 0, 7) . '&sit=pendente&tipo=' . $tipo];
            }
        }
        $atr = q_one(
            "SELECT COUNT(*) AS n, COALESCE(SUM(valor), 0) AS total FROM lancamentos WHERE perfil_id = ? AND tipo IN ('receita','despesa') AND efetivado = 0 AND cartao_id IS NULL AND data < ?",
            [$pid, $hoje]
        );
        if ((int) $atr['n'] > 0) {
            $out[] = ['chave' => 'atraso:' . $hoje, 'titulo' => 'Lançamentos atrasados',
                'mensagem' => (int) $atr['n'] . ' lançamento(s) pendente(s) com data anterior a hoje (' . brl((float) $atr['total']) . ').',
                'url' => 'lancamentos.php?mes=todos&sit=pendente'];
        }
    }
    if (permissao_pode('cartoes', 'ver')) {
        foreach (cartoes_do_perfil(true) as $c) {
            foreach ([substr($hoje, 0, 7), substr(add_meses(substr($hoje, 0, 7) . '-01', 1), 0, 7)] as $ym) {
                if (fatura_fechamento($c, $ym) === $ontem) {
                    $out[] = ['chave' => 'fecha:' . $c['id'] . ':' . $ym, 'titulo' => 'Fatura fechada: ' . $c['nome'],
                        'mensagem' => 'A fatura de ' . mes_label($ym) . ' do cartão ' . $c['nome'] . ' fechou ontem (' . brl(fatura_total((int) $c['id'], $ym)) . ' no sistema). Já dá para importá-la.',
                        'url' => 'importar_fatura.php'];
                }
            }
        }
    }
    return $out;
}

/** Grava os avisos de hoje que ainda nao existem para o usuario. @return array os avisos novos (para o envio por push) */
function notificacoes_sincronizar_itens(?string $hoje = null): array
{
    $uid = (int) usuario_id();
    $novos = [];
    foreach (notificacoes_do_dia($hoje) as $n) {
        if (!q_val('SELECT id FROM notificacoes WHERE usuario_id = ? AND chave = ?', [$uid, $n['chave']])) {
            q_insert('INSERT INTO notificacoes (usuario_id, chave, titulo, mensagem, url) VALUES (?,?,?,?,?)', [$uid, $n['chave'], $n['titulo'], $n['mensagem'], $n['url']]);
            $novos[] = $n;
        }
    }
    // mantem so os ultimos 60 dias
    q('DELETE FROM notificacoes WHERE usuario_id = ? AND criada_em < ?', [$uid, date('Y-m-d H:i:s', strtotime('-60 day'))]);
    return $novos;
}

/** Grava os avisos de hoje que ainda nao existem para o usuario. @return int novos */
function notificacoes_sincronizar(): int
{
    return count(notificacoes_sincronizar_itens());
}

function notificacoes_listar(int $limite = 15): array
{
    return q_all('SELECT id, chave, titulo, mensagem, url, criada_em, lida_em FROM notificacoes WHERE usuario_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limite)), [(int) usuario_id()]);
}

function notificacoes_nao_lidas(): int
{
    return (int) q_val('SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida_em IS NULL', [(int) usuario_id()]);
}

/** Marca como lida uma notificacao ($id) ou todas ($id = 0). */
function notificacoes_marcar_lida(int $id = 0): void
{
    if ($id) {
        q('UPDATE notificacoes SET lida_em = ? WHERE id = ? AND usuario_id = ? AND lida_em IS NULL', [date('Y-m-d H:i:s'), $id, (int) usuario_id()]);
    } else {
        q('UPDATE notificacoes SET lida_em = ? WHERE usuario_id = ? AND lida_em IS NULL', [date('Y-m-d H:i:s'), (int) usuario_id()]);
    }
}
