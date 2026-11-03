<?php
declare(strict_types=1);

/* Inicializacao sem exigir login (usada por auth_check.php, login.php e testes). */

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', getenv('UPLOAD_DIR') ?: APP_ROOT . '/uploads');

const SESSAO_LONGA_SEGUNDOS = 315360000; // 10 anos: na pratica, "para sempre"

/*
 * Configura a sessao. Normal: cookie de navegador e 8 h de inatividade. "Manter conectado" ($longa): cookie de 10 anos e arquivos
 * em pasta propria no volume de uploads (sobrevive a troca do contêiner e o gc das sessoes normais nao os apaga).
 */
function sessao_configurar(bool $longa): void
{
    // atras do Caddy a conexao interna e HTTP: o HTTPS vem no X-Forwarded-Proto
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if ($longa) {
        $dir = UPLOAD_DIR . '/.sessoes';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
    }
    ini_set('session.gc_maxlifetime', (string) ($longa ? SESSAO_LONGA_SEGUNDOS : 28800)); // normal: 8 h (o padrao do PHP, ~24 min, desloga no meio do uso)
    session_set_cookie_params(['lifetime' => $longa ? SESSAO_LONGA_SEGUNDOS : 0, 'path' => '/', 'domain' => '', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}

/* Troca a sessao atual (descarta a anterior) por uma nova, longa ou normal, e grava/remove o marcador FINMANTER. */
function sessao_reiniciar(bool $longa): void
{
    $_SESSION = [];
    session_destroy();
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $opcoes = ['expires' => $longa ? time() + SESSAO_LONGA_SEGUNDOS : time() - 3600, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'];
    setcookie('FINMANTER', $longa ? '1' : '', $opcoes);
    sessao_configurar($longa);
    session_id(session_create_id());
    session_start();
}

if (PHP_SAPI !== 'cli' && !defined('FIN_SEM_SESSAO') && session_status() === PHP_SESSION_NONE) { // FIN_SEM_SESSAO: sw.php e manifest.php nao usam sessao
    session_name('FINSESSID');
    sessao_configurar(!empty($_COOKIE['FINMANTER']));
    session_start();
} elseif (!isset($_SESSION)) {
    $_SESSION = [];
}

require_once __DIR__ . '/config_versao.php';
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/servicos/FormatoService.php';
require_once __DIR__ . '/servicos/PerfilService.php';
require_once __DIR__ . '/servicos/LimiteService.php';
require_once __DIR__ . '/servicos/PermissaoService.php';
require_once __DIR__ . '/servicos/CadastroPublicoService.php';
require_once __DIR__ . '/servicos/NotificacaoService.php';
require_once __DIR__ . '/servicos/PushService.php';
require_once __DIR__ . '/servicos/CadastroService.php';
require_once __DIR__ . '/servicos/CartaoService.php';
require_once __DIR__ . '/servicos/LancamentoService.php';
require_once __DIR__ . '/servicos/AjusteService.php';
require_once __DIR__ . '/servicos/AnexoService.php';
require_once __DIR__ . '/servicos/LoteService.php';
require_once __DIR__ . '/servicos/IndiceService.php';
require_once __DIR__ . '/servicos/RendaFixaService.php';
require_once __DIR__ . '/servicos/InvestimentoService.php';
require_once __DIR__ . '/servicos/ProjecaoService.php';
require_once __DIR__ . '/servicos/RiscoService.php';
require_once __DIR__ . '/servicos/InflacaoService.php';
require_once __DIR__ . '/servicos/CenarioFiltroService.php';
require_once __DIR__ . '/servicos/CenarioService.php';
require_once __DIR__ . '/servicos/AlocacaoService.php';
require_once __DIR__ . '/servicos/DashboardService.php';
require_once __DIR__ . '/servicos/ImportacaoService.php';
require_once __DIR__ . '/servicos/FaturaPdfService.php';
require_once __DIR__ . '/servicos/FaturaImportService.php';
require_once __DIR__ . '/servicos/ExtratoService.php';
require_once __DIR__ . '/servicos/LayoutService.php';
