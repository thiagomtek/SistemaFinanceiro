<?php
/**
 * CONTROLE E PADRONIZACAO DE VERSOES DO SISTEMA FINANCEIRO (SEMVER)
 *
 * REGRA OBRIGATORIA: a cada alteracao realizada no projeto, este arquivo DEVE ter a versao
 * incrementada para forcar o descarte de cache nos navegadores (asset_url usa versao + build).
 */

if (!defined('SISTEMA_VERSAO')) {
    define('SISTEMA_VERSAO', '1.16.0');
}
if (!defined('SISTEMA_BUILD')) {
    define('SISTEMA_BUILD', '20261103.9');
}
if (!defined('SISTEMA_RELEASE_DATA')) {
    define('SISTEMA_RELEASE_DATA', '03/11/2026');
}
if (!defined('SISTEMA_RELEASE_HORA')) {
    define('SISTEMA_RELEASE_HORA', '14:44');
}
