<?php
declare(strict_types=1);

/** Mocks de rede e de IA compartilhados pelos testes de cenario e de alocacao (sem internet e sem gastar cota). */
function cenario_instalar_mocks(): void
{
    // ------------------------------------------------------------------ mocks de rede e de IA
    $GLOBALS['FIN_BCB_MOCK'] = function (string $serie, string $ini, string $fim): array {
        $out = [];
        $mensal = in_array($serie, [IDX_IPCA_MES, IDX_IPCA_12M, IDX_INPC_MES], true);
        if ($mensal) {
            for ($t = strtotime(date('Y-m-01', strtotime($ini))); $t <= strtotime($fim); $t = strtotime(date('Y-m-01', $t) . ' +1 month')) {
                $out[] = [date('Y-m-01', $t), $serie === IDX_IPCA_12M ? 4.5 : 0.4];
            }
            return $out;
        }
        $total = max(1, (int) round((strtotime($fim) - strtotime($ini)) / 86400));
        for ($t = strtotime($ini), $i = 0; $t <= strtotime($fim); $t += 86400, $i++) {
            $d = date('Y-m-d', $t);
            if ($serie === IDX_DOLAR) {
                $out[] = [$d, 5.00 + 0.50 * $i / $total]; // sobe de 5,00 para 5,50 na janela pedida
            } elseif ($serie === IDX_CDI_DIA && eh_dia_util($d)) {
                $out[] = [$d, 0.05];
            } elseif ($serie === IDX_CDI_ANUAL && eh_dia_util($d)) {
                $out[] = [$d, 13.65];
            } elseif ($serie === IDX_SELIC) {
                $out[] = [$d, 13.75];
            }
        }
        return $out;
    };
    $GLOBALS['IBGE_FALHA'] = false;
    $GLOBALS['FIN_HTTP_MOCK'] = function (string $url): ?string {
        if (strpos($url, 'apisidra.ibge.gov.br') !== false) {
            if ($GLOBALS['IBGE_FALHA']) {
                return null;
            }
            $rows = [['NC' => 'Nivel', 'D3C' => 'Mês (Código)', 'D4C' => 'cat', 'V' => 'Valor']];
            $vals = [7169 => 4.50, 7170 => 4.00, 7445 => 4.20, 7486 => 2.0, 7558 => 3.0, 7625 => 4.0, 7660 => 6.5, 7712 => 4.0, 7766 => 6.0, 7786 => 2.5,
                7641 => 9.0, 107654 => -3.0, 5102011 => 5.0, 7656 => 5.0, 7643 => 1.0, 7634 => 40.0, 7448 => 6.0, 7449 => 4.4, 7451 => 4.5, 7485 => 3.0, 7696 => 7.0];
            foreach ([date('Ym', strtotime('first day of -1 month')), date('Ym', strtotime('first day of -2 month'))] as $per) {
                foreach ($vals as $cod => $v) {
                    $rows[] = ['D3C' => $per, 'D4C' => (string) $cod, 'V' => (string) $v];
                }
            }
            $rows[] = ['D3C' => date('Ym'), 'D4C' => '7641', 'V' => '..']; // valor ausente: ignorado
            return json_encode($rows);
        }
        if (strpos($url, 'olinda.bcb.gov.br') !== false) {
            preg_match("/Indicador eq '([^']+)'/", rawurldecode($url), $m);
            $v = ['IPCA' => [5.2, 4.7], 'Selic' => [14.0, 11.0], 'Câmbio' => [5.9, 6.0]][$m[1] ?? ''] ?? null;
            if (!$v) {
                return null;
            }
            $ano = (int) date('Y');
            $val = [];
            foreach ([0, 1] as $k) {
                $val[] = ['Indicador' => $m[1], 'Data' => date('Y-m-d'), 'DataReferencia' => (string) ($ano + $k), 'Mediana' => $v[$k]];
                $val[] = ['Indicador' => $m[1], 'Data' => date('Y-m-d', strtotime('-7 day')), 'DataReferencia' => (string) ($ano + $k), 'Mediana' => $v[$k] - 0.1];
            }
            return json_encode(['value' => $val]);
        }
        if (strpos($url, 'tesourotransparente.gov.br') !== false) {
            $base = date('d/m/Y', strtotime('-1 day'));
            $antiga = date('d/m/Y', strtotime('-8 day'));
            $v = function (string $deslocamento) { return date('d/m/Y', strtotime(date('Y-m-d', strtotime('-1 day')) . ' ' . $deslocamento)); };
            $linhas = [
                'Tesouro IPCA+;' . $v('+2 year +6 month') . ';' . $base . ';7,80;7,92;1;1;1',
                'Tesouro IPCA+;' . $v('+5 year +9 month') . ';' . $base . ';7,89;8,01;1;1;1',
                'Tesouro IPCA+;' . $v('+8 year +6 month') . ';' . $base . ';7,88;8,00;1;1;1',
                'Tesouro IPCA+;' . $v('+2 year +6 month') . ';' . $antiga . ';9,99;9,99;1;1;1',
                'Tesouro IPCA+;01/01/2020;' . $antiga . ';1,00;1,00;1;1;1',
                'Tesouro Prefixado;' . $v('+2 year +2 month') . ';' . $base . ';14,89;15,01;1;1;1',
                'Tesouro Prefixado;' . $v('+4 year +2 month') . ';' . $base . ';15,30;15,42;1;1;1',
                'Tesouro Prefixado;' . $v('+5 year +2 month') . ';' . $base . ';15,38;15,50;1;1;1',
                'Tesouro Selic;01/03/2029;' . $base . ';0,06;0,07;1;1;1',
                'linha;quebrada',
            ];
            return "Tipo Titulo;Data Vencimento;Data Base;Taxa Compra Manha;Taxa Venda Manha;PU Compra Manha;PU Venda Manha;PU Base Manha\r\n" . implode("\r\n", $linhas) . "\r\n";
        }
        if (strpos($url, 'news.google.com') !== false) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $busca = (string) ($q['q'] ?? '');
            $itens = '';
            for ($i = 1; $i <= 3; $i++) {
                $itens .= '<item><title>Manchete ' . htmlspecialchars($busca) . ' ' . $i . ' - Fonte ' . $i . '</title><link>https://exemplo.test/' . sha1($busca . $i) . '</link>'
                    . '<pubDate>' . gmdate('D, d M Y H:i:s', time() - $i * 3600) . ' GMT</pubDate><source url="https://f' . $i . '.test">Fonte ' . $i . '</source></item>';
            }
            return '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title>' . $itens . '</channel></rss>';
        }
        return null;
    };
    $GLOBALS['IA_MODO'] = 'ok';
    $GLOBALS['FIN_IA_MOCK'] = function (array $partes, array $esquema): array {
        if ($GLOBALS['IA_MODO'] === 'falha') {
            throw new RuntimeException('IA fora do ar');
        }
        if (isset($esquema['properties']['perspectiva'])) {
            return ['resumo' => 'No mês a Selic ficou estável e o IPCA desacelerou.', 'perspectiva' => 'Esperam-se cortes graduais da Selic.'];
        }
        // leitura diaria: numeros de manchete nos destaques; o 999 nao existe e o 1 pode ser de outro tema que o declarado (deve ser ignorado)
        $niveis = ['fiscal' => 4, 'politica' => 4, 'cambio' => 4, 'bolsa' => 3, 'internacional' => 3, 'juros' => 3, 'inflacao' => 2, 'imoveis' => 2];
        $temas = [];
        foreach ($niveis as $t => $n) {
            $temas[] = ['tema' => $t, 'tom' => $t === 'fiscal' ? 'negativo' : 'neutro', 'nivel_risco' => $n, 'resumo' => 'Resumo de ' . $t, 'destaques' => [1, 999]];
        }
        $temas[] = ['tema' => 'invalido', 'tom' => 'neutro', 'nivel_risco' => 3, 'resumo' => 'ignorar', 'destaques' => []];
        return ['resumo' => 'Dia de cautela com o risco fiscal.', 'temas' => $temas, 'atencao' => ['Decisão do Copom', '']];
    };


}
