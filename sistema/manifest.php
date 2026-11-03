<?php
// Manifesto do PWA (publico). Escopo e id restritos a /sistema/ para nao colidir com outros servicos do homelab.
define('FIN_SEM_SESSAO', true); // sem sessao: nao cria arquivo de sessao nem cookie a cada consulta anonima
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
$base = 'https://servidor.tail074692.ts.net:8453/sistema/';
echo json_encode([
    'id' => $base . '?app=sistema-financeiro',
    'name' => 'Sistema Financeiro',
    'short_name' => 'Financeiro',
    'description' => 'Contas, cartões, investimentos e projeção financeira.',
    'lang' => 'pt-BR',
    'start_url' => $base . 'index.php?pwa=1',
    'scope' => $base,
    'display' => 'standalone',
    'orientation' => 'any',
    'background_color' => '#0f172a',
    'theme_color' => '#0f172a',
    'icons' => [
        ['src' => $base . 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . 'assets/icons/icon-maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $base . 'assets/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts' => [
        ['name' => 'Lançamentos', 'url' => $base . 'lancamentos.php'],
        ['name' => 'Cartões e faturas', 'url' => $base . 'cartoes.php'],
        ['name' => 'Projeção', 'url' => $base . 'projecao.php'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
