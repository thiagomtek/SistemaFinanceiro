<?php
/* Cabecalho padrao (mesmo contrato do ERP): defina $PAGINA = [titulo, icone, arquivo_ativo, subtitulo, cor] antes de incluir. */
require_once __DIR__ . '/servicos/LayoutService.php';
call_user_func_array('pagina_inicio', $PAGINA);
