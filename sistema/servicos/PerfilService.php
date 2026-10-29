<?php
declare(strict_types=1);

/* PerfilService: usuario logado, perfil financeiro ativo (PF/PJ) e criacao de perfis com categorias padrao. */

function usuario_id(): ?int
{
    return isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null;
}

function exigir_login(): void
{
    if (!usuario_id()) {
        redirect('login.php');
    }
    garantir_perfil();
}

function exigir_login_api(): void
{
    if (!usuario_id()) {
        json_out(['erro' => 'Sessão expirada.'], 401);
    }
    garantir_perfil();
}

function garantir_perfil(): void
{
    $uid = usuario_id();
    // usuarios criados por um administrador compartilham os dados (perfil) do dono
    $dono = (int) (q_val('SELECT COALESCE(dono_id, id) FROM usuarios WHERE id = ?', [$uid]) ?: $uid);
    $pid = $_SESSION['perfil_id'] ?? null;
    if ($pid && q_val('SELECT id FROM perfis WHERE id = ? AND usuario_id = ?', [$pid, $dono])) {
        return;
    }
    $primeiro = q_val('SELECT id FROM perfis WHERE usuario_id = ? ORDER BY id LIMIT 1', [$dono]);
    if (!$primeiro) {
        $primeiro = criar_perfil($dono, 'Pessoal', 'PF', null);
    }
    $_SESSION['perfil_id'] = (int) $primeiro;
}

function perfil_id(): int
{
    return (int) $_SESSION['perfil_id'];
}

function perfil_atual(): array
{
    return q_one('SELECT * FROM perfis WHERE id = ?', [perfil_id()]) ?? [];
}

/** Cria o perfil interno do usuario (um por usuario; nao ha mais escolha PF/PJ na tela) com as categorias padrao. */
function criar_perfil(int $uid, string $nome, string $tipo, ?string $doc): int
{
    $pid = q_insert('INSERT INTO perfis (usuario_id, nome, tipo, documento) VALUES (?,?,?,?)', [$uid, $nome, $tipo, $doc]);
    $despesas = ['Alimentação' => '#f97316', 'Casa' => '#0ea5e9', 'Transporte' => '#6366f1', 'Saúde' => '#ef4444',
        'Educação' => '#14b8a6', 'Lazer' => '#a855f7', 'Eletrônicos' => '#64748b', 'Outros' => '#94a3b8'];
    $receitas = ['Salário' => '#16a34a', 'Outras receitas' => '#22c55e'];
    foreach ($despesas as $n => $c) {
        q_insert('INSERT INTO categorias (perfil_id, nome, tipo, cor) VALUES (?,?,?,?)', [$pid, $n, 'despesa', $c]);
    }
    foreach ($receitas as $n => $c) {
        q_insert('INSERT INTO categorias (perfil_id, nome, tipo, cor) VALUES (?,?,?,?)', [$pid, $n, 'receita', $c]);
    }
    garantir_categorias_sistema($pid);
    return $pid;
}
