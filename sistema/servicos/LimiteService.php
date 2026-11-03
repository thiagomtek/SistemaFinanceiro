<?php
declare(strict_types=1);

/* =========================================================================
   LimiteService: limites de uso por janela de tempo (IP, usuario, dia) e cotas de recursos caros (RN-52).
   - Contadores em `parametros` (chave "rl:<sha1>", valor "inicio|contagem|janela"), sem tabela nova; os vencidos sao varridos aos poucos.
   - O IP do cliente e o primeiro valor do X-Forwarded-For (o Caddy substitui o cabecalho); sem ele, o REMOTE_ADDR.
   - Cotas de IA (Gemini): por usuario e global por dia; o administrador do sistema nao tem cota por usuario.
   ========================================================================= */

const IA_COTA_USUARIO_DIA = 15;
const IA_COTA_GLOBAL_DIA = 150;

/** IP do cliente (valido) ou "0.0.0.0". So confie no X-Forwarded-For atras do Caddy: o servico nao e exposto direto. */
function cliente_ip(): string
{
    $xff = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    if ($xff !== '' && filter_var($xff, FILTER_VALIDATE_IP)) {
        return $xff;
    }
    $ra = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ra, FILTER_VALIDATE_IP) ? $ra : '0.0.0.0';
}

function limite_chave(string $chave): string
{
    return 'rl:' . sha1($chave);
}

/** Lê o contador. @return array{0: int, 1: int, 2: int}|null [inicio, contagem, janela] ainda valido */
function limite_ler(string $chave, int $agora): ?array
{
    $v = parametro_get(limite_chave($chave));
    if ($v === null) {
        return null;
    }
    [$ini, $n, $j] = array_map('intval', explode('|', $v) + [0, 0, 0]);
    return $agora - $ini < $j ? [$ini, $n, $j] : null;
}

/**
 * Conta mais um uso na janela e diz se ainda esta dentro do maximo.
 * @return bool true = permitido; false = estourou (o uso que estourou tambem e contado)
 */
function limite_consumir(string $chave, int $max, int $janelaS, ?int $agora = null): bool
{
    $agora = $agora ?? time();
    $at = limite_ler($chave, $agora);
    [$ini, $n] = $at ? [$at[0], $at[1] + 1] : [$agora, 1];
    parametro_set(limite_chave($chave), $ini . '|' . $n . '|' . $janelaS);
    if (random_int(1, 40) === 1) {
        limite_varrer($agora);
    }
    return $n <= $max;
}

/** Segundos que faltam para liberar (0 = liberado), sem contar um novo uso. */
function limite_bloqueado_por(string $chave, int $max, int $janelaS, ?int $agora = null): int
{
    $agora = $agora ?? time();
    $at = limite_ler($chave, $agora);
    return $at && $at[1] >= $max ? max(1, $at[0] + $at[2] - $agora) : 0;
}

/** Apaga contadores vencidos (lote pequeno por vez). */
function limite_varrer(?int $agora = null): void
{
    $agora = $agora ?? time();
    foreach (q_all("SELECT chave, valor FROM parametros WHERE chave LIKE 'rl:%' LIMIT 300") as $r) {
        [$ini, , $j] = array_map('intval', explode('|', (string) $r['valor']) + [0, 0, 0]);
        if ($agora - $ini >= $j) {
            q('DELETE FROM parametros WHERE chave = ?', [$r['chave']]);
        }
    }
}

/** Em endpoints api_*.php: responde 429 quando o usuario passa do limite do recurso na janela. */
function limite_exigir_api(string $recurso, int $max, int $janelaS): void
{
    if (!limite_consumir('uso:' . $recurso . ':' . (int) usuario_id(), $max, $janelaS)) {
        header('Retry-After: ' . $janelaS);
        json_out(['erro' => 'Muitas requisições. Aguarde um pouco e tente de novo.'], 429);
    }
}

/**
 * Consome uma leitura por IA (Gemini). Lanca RuntimeException quando a cota do dia acabou. Fora do navegador (rotina diaria) e em
 * teste com IA simulada nao ha cota.
 */
function ia_cota_consumir(?bool $web = null): void
{
    $web = $web ?? (PHP_SAPI !== 'cli' && !isset($GLOBALS['FIN_IA_MOCK'])); // $web so e informado nos testes
    if (!$web) {
        return;
    }
    $dia = date('Y-m-d');
    $uid = (int) usuario_id();
    if (!limite_consumir('ia:global:' . $dia, IA_COTA_GLOBAL_DIA, 86400)) {
        throw new RuntimeException('O limite diário de leituras por IA do sistema foi atingido. Tente amanhã.');
    }
    if (!usuario_admin_sistema($uid) && !limite_consumir('ia:u:' . $uid . ':' . $dia, IA_COTA_USUARIO_DIA, 86400)) {
        throw new RuntimeException('Você atingiu o limite diário de ' . IA_COTA_USUARIO_DIA . ' leituras por IA. Tente amanhã.');
    }
}
