<?php
declare(strict_types=1);

/* =========================================================================
   FaturaPdfService: le uma fatura de cartao em PDF (inclusive protegida por senha) com IA (Gemini) e devolve
   os lancamentos no mesmo formato da importacao de planilhas (ImportacaoService).

   - O PDF e enviado ao Gemini como arquivo (layout de faturas e em varias colunas; texto puro embaralha valores).
   - A senha do PDF e usada so para remover a protecao com qpdf, em arquivo temporario 0600; nunca e gravada.
   - O PDF e os temporarios sao apagados logo apos a leitura. Nada do conteudo e guardado alem do JSON da revisao.
   - A chave da IA vem da variavel de ambiente GEMINI_API_KEY (nunca do repositorio).
   ========================================================================= */

const FATURA_PDF_MODELOS = ['gemini-3.5-flash', 'gemini-flash-latest', 'gemini-3.5-flash-lite', 'gemini-flash-lite-latest'];

function fatura_pdf_chave_ia(): string
{
    $k = trim((string) getenv('GEMINI_API_KEY'));
    if ($k === '') {
        throw new RuntimeException('A leitura de PDF com IA não está configurada (variável GEMINI_API_KEY ausente).');
    }
    return $k;
}

/** Remove a protecao por senha (se houver). Retorna o caminho de um PDF legivel (pode ser o proprio $pdf). */
function fatura_pdf_desproteger(string $pdf, string $senha, array &$temporarios): string
{
    $qpdf = trim((string) @shell_exec('command -v qpdf 2>/dev/null'));
    if ($qpdf === '') {
        if ($senha !== '') {
            throw new RuntimeException('Este servidor não consegue abrir PDF protegido por senha (qpdf ausente).');
        }
        return $pdf;
    }
    // exit 0 = precisa de senha; 2 = nao protegido
    exec(escapeshellarg($qpdf) . ' --requires-password ' . escapeshellarg($pdf) . ' 2>/dev/null', $o, $rc);
    if ($rc === 2 || ($rc !== 0 && $rc !== 3)) {
        return $pdf;
    }
    if ($senha === '') {
        throw new InvalidArgumentException('Este PDF é protegido por senha. Informe a senha para ler a fatura.');
    }
    $arqSenha = tempnam(sys_get_temp_dir(), 'fps');
    chmod($arqSenha, 0600);
    file_put_contents($arqSenha, $senha);
    $saida = tempnam(sys_get_temp_dir(), 'fpo');
    $temporarios[] = $arqSenha;
    $temporarios[] = $saida;
    exec(escapeshellarg($qpdf) . ' --password-file=' . escapeshellarg($arqSenha) . ' --decrypt ' . escapeshellarg($pdf) . ' ' . escapeshellarg($saida) . ' 2>&1', $o2, $rc2);
    if ($rc2 !== 0 && $rc2 !== 3) { // 3 = avisos
        throw new InvalidArgumentException('Senha incorreta ou PDF inválido.');
    }
    return $saida;
}

function fatura_pdf_esquema(): array
{
    $texto = ['type' => 'STRING'];
    $textoNull = ['type' => 'STRING', 'nullable' => true];
    return [
        'type' => 'OBJECT',
        'properties' => [
            'emissor' => $texto, 'produto' => $textoNull, 'final_cartao' => $textoNull,
            'vencimento' => $texto, 'fechamento' => $textoNull, 'total_fatura' => ['type' => 'NUMBER'],
            'observacoes' => $textoNull,
            'lancamentos' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'data' => $texto, 'descricao' => $texto, 'valor' => ['type' => 'NUMBER'],
                        'parcela' => $textoNull, 'tipo' => ['type' => 'STRING', 'enum' => ['compra', 'encargo', 'credito']],
                        'categoria' => $textoNull,
                    ],
                    'required' => ['data', 'descricao', 'valor', 'tipo'],
                ],
            ],
        ],
        'required' => ['emissor', 'vencimento', 'total_fatura', 'lancamentos'],
    ];
}

function fatura_pdf_prompt(array $categorias): string
{
    return "Você extrai dados de faturas de cartão de crédito brasileiras (PDF) e responde SOMENTE com JSON no esquema pedido.\n"
        . "Regras:\n"
        . "- emissor: banco/instituição que emite a fatura (ex.: Itaú, Nubank, Bradescard); produto: nome do cartão COMO ESTÁ IMPRESSO na fatura, sem inventar (ex.: 'Black', 'AMAZON MASTERCARD PLATINUM'); final_cartao: 4 últimos dígitos, se houver.\n"
        . "- vencimento e fechamento em AAAA-MM-DD. total_fatura: o valor TOTAL A PAGAR desta fatura (o total da fatura atual, nunca simulações de parcelamento, rotativo ou pagamento mínimo).\n"
        . "- lancamentos: SOMENTE os itens cobrados NESTA fatura: compras e saques (tipo compra), anuidade, juros, multa, IOF e tarifas (tipo encargo) e estornos, descontos ou créditos (tipo credito, com valor NEGATIVO).\n"
        . "- Faturas Bradesco/Bradescard: tabela Data | Descrição | Valor R$; valor com '-' no final é crédito (negativo); a parcela vem como (nn/mm) na descrição. Se a mesma compra aparece com várias parcelas na fatura, inclua todas as linhas. 'PAGAMENTO RECEBIDO - OBRIGADO' é pagamento da fatura anterior: NÃO inclua.
"
        . "- NÃO inclua: pagamento da fatura anterior, saldo anterior, totais, limites, simulações, nem 'compras parceladas - próximas faturas' (parcelas futuras).\n"
        . "- data: data da compra em AAAA-MM-DD. As faturas mostram só dia/mês: infira o ano; a data nunca é posterior à emissão da fatura (compras de dezembro em fatura de janeiro são do ano anterior).\n"
        . "- descricao: o nome do estabelecimento como aparece (inclua a parcela como aparece, ex.: 'MAGAZINE LUIZA 02/10'); parcela: 'n/m' quando houver, senão nulo.\n"
        . "- valor: número decimal com ponto, em reais (compras internacionais: use o valor já convertido em R$). Compras e encargos positivos; créditos negativos.\n"
        . "- Uma linha por cobrança. Não agrupe nem some itens. Os valores podem estar em colunas separadas da descrição: associe cada valor à sua linha pela posição visual.\n"
        . "- ATENÇÃO ao layout: em faturas do Itaú a seção 'Lançamentos: compras e saques' tem 2 ou 3 colunas lado a lado (DATA, ESTABELECIMENTO, VALOR EM R$). A linha logo abaixo do estabelecimento (categoria e cidade, ex.: 'eletronicos OSASCO') NÃO é um item. O valor fica no fim da MESMA linha do estabelecimento; não use o valor da linha vizinha. Na seção 'Compras parceladas - próximas faturas' os itens são futuros: ignore-os.\n"
        . "- AUTOCONFERÊNCIA: a soma de (compras + encargos + créditos) deve ser igual ao total dos lançamentos atuais da fatura. Releia o PDF e corrija os itens antes de responder se a soma não bater.\n"
        . "- categoria: escolha UMA das categorias abaixo que melhor descreve o estabelecimento, ou null se não houver correspondência clara.\n"
        . 'Categorias disponíveis: ' . ($categorias ? implode(', ', $categorias) : '(nenhuma)') . "\n"
        . "- observacoes: avisos relevantes (ex.: itens ilegíveis); senão nulo.";
}

/**
 * Chama o Gemini (varios modelos, com nova tentativa em alta demanda) e devolve o JSON decodificado.
 * $partes: partes do conteudo (texto e/ou inline_data); $chaveObrigatoria: chave que a resposta precisa ter.
 * Em teste, $GLOBALS['FIN_IA_MOCK'] (callable($partes, $esquema): array) substitui a chamada de rede.
 */
function ia_gemini_json(array $partes, array $esquema, string $chaveObrigatoria, string $erroPrefixo): array
{
    if (isset($GLOBALS['FIN_IA_MOCK']) && is_callable($GLOBALS['FIN_IA_MOCK'])) {
        $d = ($GLOBALS['FIN_IA_MOCK'])($partes, $esquema);
        $d['_modelo'] = 'mock';
        $d['_tentativas'] = [];
        return $d;
    }
    $chave = fatura_pdf_chave_ia();
    $corpo = json_encode([
        'contents' => [['role' => 'user', 'parts' => $partes]],
        'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json', 'responseSchema' => $esquema],
    ], JSON_UNESCAPED_UNICODE);

    $ultimoErro = 'sem resposta';
    $tentativas = [];
    foreach (FATURA_PDF_MODELOS as $modelo) {
        for ($tentativa = 0; $tentativa < 2; $tentativa++) {
            // a chave vai no cabecalho (na URL ela apareceria em logs de proxy e mensagens de erro)
            $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $modelo . ':generateContent');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $chave], CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15,
            ]);
            $resp = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $erroCurl = curl_error($ch);
            curl_close($ch);
            if (!in_array($http, [429, 500, 503], true) || $tentativa === 1) {
                break;
            }
            sleep(5); // alta demanda temporaria: tenta de novo o mesmo modelo antes de cair para outro
        }
        if ($resp === false) {
            $ultimoErro = 'falha de rede: ' . $erroCurl;
            $tentativas[] = $modelo . ': ' . $ultimoErro;
            continue;
        }
        $json = json_decode((string) $resp, true);
        if ($http !== 200 || !is_array($json)) {
            // nao registra o corpo completo (pode conter a chave em mensagens de erro)
            $ultimoErro = "modelo $modelo respondeu HTTP $http" . (isset($json['error']['message']) ? ': ' . mb_substr(preg_replace('/key=[^\s&"]+/', 'key=***', (string) $json['error']['message']), 0, 160) : '');
            $tentativas[] = $ultimoErro;
            continue;
        }
        $txt = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $dados = json_decode((string) $txt, true);
        if (is_array($dados) && isset($dados[$chaveObrigatoria])) {
            $dados['_modelo'] = $modelo;
            $dados['_tentativas'] = $tentativas;
            return $dados;
        }
        $ultimoErro = "modelo $modelo devolveu uma resposta fora do formato";
        $tentativas[] = $ultimoErro;
    }
    throw new RuntimeException($erroPrefixo . ' (' . $ultimoErro . ').');
}

/** Chama o Gemini com o PDF. Retorna o array decodificado da resposta (sem validacao). */
function fatura_pdf_chamar_ia(string $pdfPath, array $categorias): array
{
    fatura_pdf_chave_ia_ou_mock();
    $conteudo = (string) file_get_contents($pdfPath);
    if ($conteudo === '' || strncmp($conteudo, '%PDF', 4) !== 0) {
        throw new InvalidArgumentException('O arquivo enviado não é um PDF válido.');
    }
    return ia_gemini_json([
        ['text' => fatura_pdf_prompt($categorias)],
        ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($conteudo)]],
    ], fatura_pdf_esquema(), 'lancamentos', 'Não foi possível ler a fatura com a IA');
}

/** Falha cedo (antes de ler o arquivo) quando a chave da IA nao esta configurada, salvo em teste com mock. */
function fatura_pdf_chave_ia_ou_mock(): void
{
    if (!isset($GLOBALS['FIN_IA_MOCK'])) {
        fatura_pdf_chave_ia();
    }
}

/**
 * Valida/normaliza a resposta e converte para linhas de importacao (forcado a um cartao).
 * @return array{linhas: array, avisos: array, fatura: array}
 */
function fatura_pdf_normalizar(array $d): array
{
    $venc = parse_data($d['vencimento'] ?? '');
    $total = round((float) ($d['total_fatura'] ?? 0), 2);
    if (!$venc || $total <= 0) {
        throw new RuntimeException('A IA não identificou o vencimento ou o total da fatura. Confira o PDF.');
    }
    $avisos = [];
    $linhas = [];
    foreach (array_slice((array) $d['lancamentos'], 0, 600) as $i => $x) {
        $data = parse_data($x['data'] ?? '');
        $valor = round((float) ($x['valor'] ?? 0), 2);
        $desc = trim((string) ($x['descricao'] ?? ''));
        if (!$data || $desc === '' || $valor == 0.0) {
            $avisos[] = 'Item ' . ($i + 1) . ' ignorado (dados incompletos).';
            continue;
        }
        // pagamento da fatura anterior nao e item da fatura (a IA as vezes o inclui)
        if (preg_match('/pagamento\s+(recebido|efetuado|em\s+conta|da\s+fatura)|obrigado|saldo\s+anterior|pgto\s+(recebido|fatura)/iu', $desc) && ($valor < 0 || ($x['tipo'] ?? '') === 'credito')) {
            continue;
        }
        $credito = $valor < 0 || ($x['tipo'] ?? '') === 'credito';
        if ($credito) {
            $valor = -abs($valor); // estorno/desconto: item negativo, para a soma bater com o total da fatura
        }
        $linhas[] = [
            'tipo' => 'despesa', 'data' => $data, 'descricao' => mb_substr($desc, 0, 160), 'valor' => $valor, 'bruto' => $valor,
            'conta' => '', 'destino' => '', 'efetivado' => 0, 'categoria' => trim((string) ($x['categoria'] ?? '')), 'subcategoria' => '',
            'tags' => $credito ? 'credito' : (($x['tipo'] ?? '') === 'encargo' ? 'encargo' : ''), 'parcela' => trim((string) ($x['parcela'] ?? '')),
            'aba' => 'PDF', 'linha' => $i + 1, 'fatura_mes' => substr($venc, 0, 7),
        ];
    }
    if (!$linhas) {
        throw new RuntimeException('Nenhum lançamento foi encontrado na fatura.');
    }
    $soma = round(array_sum(array_column($linhas, 'valor')), 2);
    $fatura = [
        'emissor' => trim((string) ($d['emissor'] ?? '')), 'produto' => trim((string) ($d['produto'] ?? '')),
        'final_cartao' => trim((string) ($d['final_cartao'] ?? '')), 'vencimento' => $venc, 'fechamento' => parse_data($d['fechamento'] ?? ''),
        'total' => $total, 'soma_itens' => $soma, 'diferenca' => round($total - $soma, 2), 'modelo' => $d['_modelo'] ?? '', 'tentativas' => $d['_tentativas'] ?? [],
    ];
    if (!empty($d['observacoes'])) {
        $avisos[] = 'IA: ' . mb_substr((string) $d['observacoes'], 0, 200);
    }
    return ['linhas' => $linhas, 'avisos' => $avisos, 'fatura' => $fatura];
}

/** Fluxo completo: desprotege, le com a IA e normaliza. Apaga os temporarios. */
function fatura_pdf_ler(string $pdfPath, string $senha = ''): array
{
    $temporarios = [];
    try {
        $legivel = fatura_pdf_desproteger($pdfPath, $senha, $temporarios);
        $categorias = array_values(array_unique(array_column(categorias_do_perfil('despesa'), 'nome')));
        return fatura_pdf_normalizar(fatura_pdf_chamar_ia($legivel, $categorias));
    } finally {
        foreach ($temporarios as $t) {
            @unlink($t);
        }
    }
}

/** Sugere o cartao pelo emissor/produto da fatura (maior coincidencia de palavras com o nome do cartao). */
function fatura_pdf_sugerir_cartao(array $fatura): ?int
{
    $alvo = ' ' . norm_texto($fatura['emissor'] . ' ' . $fatura['produto']) . ' ';
    $melhor = null;
    $pontos = 0;
    foreach (cartoes_do_perfil() as $c) {
        $p = 0;
        foreach (explode(' ', norm_texto($c['nome'])) as $palavra) {
            if (strlen($palavra) >= 3 && strpos($alvo, ' ' . $palavra . ' ') !== false) {
                $p++;
            }
        }
        if ($p > $pontos) {
            $pontos = $p;
            $melhor = (int) $c['id'];
        }
    }
    return $melhor;
}
