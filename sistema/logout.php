<?php
$PAGINA_PUBLICA = true;
require_once __DIR__ . '/auth_check.php';
sessao_reiniciar(false); // descarta a sessao (inclusive a longa) e remove o marcador FINMANTER
$_SESSION = [];
session_destroy();
redirect('login.php');
