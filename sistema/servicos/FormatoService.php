<?php
declare(strict_types=1);

/* FormatoService: formatacao, parse de valores/datas, CSRF, flash e utilidades de saida (compativel com PHP 7.4). */

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

/** URL de asset com cache-busting pela versao do sistema (config_versao.php). */
function asset_url(string $caminho): string
{
    return $caminho . '?v=' . rawurlencode(SISTEMA_VERSAO . '.' . SISTEMA_BUILD);
}

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function brl($v): string
{
    return 'R$ ' . number_format((float) $v, 2, ',', '.');
}

function data_br(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    return date('d/m/Y', strtotime($iso));
}

function redirect(string $url)
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_exigir(): void
{
    $enviado = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($enviado) || !hash_equals(csrf_token(), $enviado)) {
        http_response_code(419);
        exit('Sessão expirada ou requisição inválida. Recarregue a página.');
    }
}

function flash(?string $msg = null, string $tipo = 'success'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'tipo' => $tipo];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/**
 * Converte "1.234,56", "1234.56", "-55" para float.
 * Sem virgula, "1.500" e "1.234.567" sao milhares no formato brasileiro (dinheiro); "0.500", "12.5" e "1234.567" seguem decimais.
 * Para taxas e percentuais ("13.875" e 13,875) use $dinheiro = false: o ponto e sempre decimal.
 */
function parse_valor($v, bool $dinheiro = true): float
{
    if (is_int($v) || is_float($v)) {
        return (float) $v;
    }
    $s = trim((string) $v);
    $s = preg_replace('/[^\d,.\-]/u', '', $s) ?? '';
    if ($s === '' || $s === '-') {
        return 0.0;
    }
    if (str_contains($s, ',')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif ($dinheiro && preg_match('/^-?[1-9]\d{0,2}(\.\d{3})+$/', $s)) {
        $s = str_replace('.', '', $s);
    }
    return (float) $s;
}

/** Mes AAAA-MM valido (01 a 12) ou o padrao. Aceita tambem os valores extras informados em $extras (ex.: 'todos'). */
function mes_valido($v, string $padrao, array $extras = []): string
{
    $s = is_string($v) ? $v : '';
    return (in_array($s, $extras, true) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $s)) ? $s : $padrao;
}

function add_dias(string $iso, int $n): string
{
    return date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' day', strtotime($iso)));
}

/** Aceita DD/MM/AAAA, AAAA-MM-DD ou serial do Excel. Retorna AAAA-MM-DD ou null. */
function parse_data($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
        return gmdate('Y-m-d', (int) round(((float) $v - 25569) * 86400));
    }
    $s = trim((string) $v);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return "$m[1]-$m[2]-$m[3]";
    }
    return null;
}

/** Soma $n meses mantendo o dia (limitado ao ultimo dia do mes de destino). */
function add_meses(string $iso, int $n): string
{
    $d = new DateTimeImmutable($iso);
    $dia = (int) $d->format('j');
    $primeiro = $d->modify('first day of this month')->modify(($n >= 0 ? '+' : '') . $n . ' month');
    $ultimo = (int) $primeiro->format('t');
    return $primeiro->setDate((int) $primeiro->format('Y'), (int) $primeiro->format('n'), min($dia, $ultimo))->format('Y-m-d');
}

/** Soma $n periodos ($unidade: dia, semana, mes, ano) a uma data. */
function add_periodo(string $iso, int $n, string $unidade): string
{
    switch ($unidade) {
        case 'dia':
            return date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' day', strtotime($iso)));
        case 'semana':
            return date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . ($n * 7) . ' day', strtotime($iso)));
        case 'ano':
            return add_meses($iso, 12 * $n);
        default:
            return add_meses($iso, $n);
    }
}

function mes_label(string $ym): string
{
    static $n = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    [$a, $m] = explode('-', $ym);
    return $n[(int) $m] . '/' . substr($a, 2);
}

function norm_texto(string $s): string
{
    static $acentos = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ];
    $s = strtr(mb_strtolower(trim($s)), $acentos);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s);
}

function json_out($data, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/** Opcoes de <option> para selects. */
function opcoes(array $linhas, string $valorCampo = 'id', string $rotuloCampo = 'nome', $selecionado = null, bool $vazio = false, string $vazioRotulo = '—'): string
{
    $h = $vazio ? '<option value="">' . e($vazioRotulo) . '</option>' : '';
    foreach ($linhas as $l) {
        $sel = ((string) $l[$valorCampo] === (string) $selecionado) ? ' selected' : '';
        $h .= '<option value="' . e($l[$valorCampo]) . '"' . $sel . '>' . e($l[$rotuloCampo]) . '</option>';
    }
    return $h;
}

function classe_valor(string $tipo): string
{
    return $tipo === 'receita' ? 'text-success' : ($tipo === 'despesa' ? 'text-danger' : 'text-info');
}
