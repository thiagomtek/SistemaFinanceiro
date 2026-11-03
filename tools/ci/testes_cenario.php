<?php
declare(strict_types=1);

/**
 * Testes do cenario economico e do risco consolidado (RN-45 a RN-48): indicadores de inflacao (BCB, IBGE, Focus), juro real,
 * inflacao percebida e da cesta do usuario, manchetes + leitura por IA (com falha da IA), fechamento mensal, risco consolidado,
 * dolarizacao e historico. Nao usa internet nem a IA de verdade. Uso: php tools/ci/testes_cenario.php
 */

putenv('DB_DRIVER=sqlite');
putenv('DB_SQLITE=:memory:');

require __DIR__ . '/../../sistema/bootstrap.php';
require __DIR__ . '/sqlite_schema.php';
db()->exec(schema_sqlite());

$falhas = 0;
function ok(bool $cond, string $msg): void
{
    global $falhas;
    echo ($cond ? '  [ok] ' : '  [FALHA] ') . $msg . "\n";
    if (!$cond) {
        $falhas++;
    }
}
function igual($a, $b, string $msg, float $tol = 0.01): void
{
    ok(abs((float) $a - (float) $b) <= $tol, $msg . " (esperado $b, obtido $a)");
}

require __DIR__ . '/mocks_cenario.php';
cenario_instalar_mocks();

$uid = q_insert('INSERT INTO usuarios (nome, email, senha_hash, ultimo_acesso) VALUES (?,?,?,?)', ['T', 't@t', 'x', date('Y-m-d H:i:s')]); // a rotina diaria so calcula o risco de quem entrou nos ultimos 45 dias
$_SESSION['usuario_id'] = $uid;
garantir_perfil();
$conta = q_insert('INSERT INTO contas (perfil_id, nome, tipo, saldo_inicial) VALUES (?,?,?,?)', [perfil_id(), 'Itaú', 'corrente', 5000]);

echo "== indicadores: BCB, IBGE e Focus\n";
$r = inflacao_atualizar(true);
ok($r['atualizado'] && count($r['fontes']) === 5 && !$r['erros'], 'as cinco fontes (CDI/Selic, IPCA/INPC/dólar, IBGE, Focus e Tesouro) atualizam sem erro');
$res = inflacao_resumo();
igual($res['ipca_12m']['valor'], 4.5, 'IPCA em 12 meses');
igual($res['cdi'], 13.65, 'CDI anual');
igual($res['juro_real_12m'], (1.1365 / 1.045 - 1) * 100, 'juro real = (1+CDI)/(1+IPCA)-1', 0.01);
ok(abs(juro_real(10, 10)) < 0.001 && juro_real(10, 12) < 0, 'juro real zero com juro igual à inflação e negativo abaixo dela');
$w = (13 - (int) date('n')) / 12;
igual($res['ipca_esperado_12m'], $w * 5.2 + (1 - $w) * 4.7, 'IPCA esperado 12 meses = Focus ponderado pelos meses que faltam no ano');
igual($res['focus']['selic_ano_seguinte'], 11.0, 'Focus da Selic do ano seguinte (valor mais recente)');
igual($res['dolar']['valor'], 5.5, 'dólar mais recente', 0.01);
ok($res['dolar_var_12m'] !== null && $res['dolar_var_12m'] > 0, 'variação do dólar em 12 meses calculada (dólar subiu na série de teste)');
$r2 = inflacao_atualizar(false);
ok($r2['atualizado'] === false, 'sem forçar, não busca de novo dentro do intervalo');
$GLOBALS['IBGE_FALHA'] = true;
$r3 = inflacao_atualizar(true);
ok(count($r3['erros']) === 1 && strpos($r3['erros'][0], 'IBGE') !== false && count($r3['fontes']) === 4, 'IBGE fora do ar: erro só dessa fonte, as outras seguem');
$GLOBALS['IBGE_FALHA'] = false;

echo "== inflacao percebida: itens acima do IPCA\n";
$it = inflacao_itens();
igual($it['geral']['valor'], 4.5, 'IPCA geral de referência');
ok($it['itens'][0]['rotulo'] === 'Passagem aérea' && $it['itens'][0]['acima'], 'o item que mais sobe acima do IPCA vem primeiro');
$usado = array_values(array_filter($it['itens'], function ($x) { return $x['rotulo'] === 'Automóvel usado'; }))[0];
ok($usado['spread'] < 0 && !$usado['acima'], 'carro usado abaixo do IPCA aparece como abaixo (o dado manda, não a percepção)');
$novo = array_values(array_filter($it['itens'], function ($x) { return $x['rotulo'] === 'Automóvel novo'; }))[0];
igual($novo['var_12m'], 9.0, 'valor ausente ("..") do mês atual foi ignorado e vale o último mês com dado');

echo "== inflacao do bolso: recorrentes e cesta\n";
$cat = function (string $nome) { return (int) q_val('SELECT id FROM categorias WHERE perfil_id = ? AND nome = ?', [perfil_id(), $nome]); };
foreach (['Alimentação', 'Saúde', 'Casa', 'Outros'] as $nome) {
    if (!$cat($nome)) {
        q_insert('INSERT INTO categorias (perfil_id, nome, tipo) VALUES (?,?,?)', [perfil_id(), $nome, 'despesa']);
    }
}
$grupoPlano = 'rec-plano';
foreach ([-8 => 700.0, -4 => 700.0, -1 => 770.0] as $off => $v) {
    q_insert('INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, grupo_id, recorrencia) VALUES (?,?,?,?,?,?,?,?,?)',
        [perfil_id(), 'despesa', 'Plano de Saúde', $v, date('Y-m-10', strtotime("first day of $off month")), 1, $conta, $grupoPlano, 'fixa']);
}
q_insert('INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, grupo_id, recorrencia) VALUES (?,?,?,?,?,?,?,?,?)',
    [perfil_id(), 'despesa', 'Internet', 100, date('Y-m-10', strtotime('first day of -3 month')), 1, $conta, 'rec-net', 'fixa']);
q_insert('INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, grupo_id, recorrencia) VALUES (?,?,?,?,?,?,?,?,?)',
    [perfil_id(), 'despesa', 'Internet', 100, date('Y-m-10', strtotime('first day of -1 month')), 1, $conta, 'rec-net', 'fixa']);
$rec = inflacao_pessoal_recorrentes();
ok(count($rec) === 1 && $rec[0]['descricao'] === 'Plano de Saúde', 'só o gasto recorrente que mudou de valor aparece (a internet fixa não)');
igual($rec[0]['var_pct'], 10.0, 'reajuste do plano de saúde: 700 → 770 = +10%');
ok($rec[0]['meses'] >= 6 && $rec[0]['anualizada'] !== null, 'com 6 meses ou mais, calcula o equivalente em % ao ano');
foreach ([['Alimentação', 600], ['Saúde', 400], ['Outros', 1000]] as [$nome, $v]) {
    q_insert('INSERT INTO lancamentos (perfil_id, tipo, descricao, valor, data, efetivado, conta_id, categoria_id) VALUES (?,?,?,?,?,?,?,?)',
        [perfil_id(), 'despesa', 'Gasto ' . $nome, $v, date('Y-m-05', strtotime('first day of -2 month')), 1, $conta, $cat($nome)]);
}
$ces = inflacao_pessoal_cesta();
// Plano de Saude (700+700+770, categoria nula) e Internet entram no total mas sem categoria; so Alimentacao e Saude ligam a grupo
igual($ces['ipca_cesta'], (600 * 4.0 + 400 * 6.5) / 1000, 'IPCA da cesta = média dos grupos ponderada pelo gasto em cada categoria', 0.01);
ok($ces['cobertura_pct'] < 50 && !$ces['confiavel'], 'cobertura baixa (muito gasto sem categoria ligada ao IPCA): a cesta é marcada como não confiável');

echo "== noticias e leitura do dia\n";
$d = cenario_gerar_dia();
ok($d['origem'] === 'ia' && $d['manchetes'] > 8 && $d['manchetes'] === (int) q_val('SELECT COUNT(*) FROM cenario_noticias'), 'dia gerado com a leitura da IA e todas as manchetes coletadas gravadas');
$ds = $d['descartadas'];
ok($ds['fonte'] === 8 && $ds['ruido'] === 8 && $ds['fora_do_pais'] === 7 && $ds['fora_do_tema'] === 8, 'a coleta descarta, em cada tema, uma manchete de cada motivo (fora_do_pais só nos 7 temas do Brasil) e informa a contagem');
ok($ds['ia'] === 1, 'a IA também descartou uma manchete (a de número 2); o número inexistente foi ignorado');
igual((int) q_val("SELECT COUNT(*) FROM cenario_noticias WHERE fonte = 'Blog do Esmael' OR titulo LIKE '%Celebridade%' OR titulo LIKE '%Prefeitura inaugura%'"), 0, 'nenhuma manchete recusada foi gravada', 0);
igual((int) q_val("SELECT COUNT(*) FROM cenario_noticias WHERE titulo LIKE '%Portugal%' AND tema <> 'internacional'"), 0, 'nenhuma matéria sobre Portugal em tema do Brasil', 0);
igual((int) q_val("SELECT COUNT(*) FROM cenario_noticias WHERE titulo LIKE '%Portugal%' AND tema = 'internacional'"), 1, 'a mesma matéria aparece no tema internacional', 0);
$dia = cenario_dia();
ok(($dia['descartadas']['fonte'] ?? 0) === 8, 'a contagem de descartes fica guardada no dia para a tela');
ok($dia !== null && $dia['origem'] === 'ia' && count($dia['temas']) === 8, 'oito temas lidos; o tema inválido devolvido pela IA foi ignorado');
igual($dia['temas']['fiscal']['nivel_risco'], 4, 'nível de risco do tema fiscal');
ok(count($dia['atencao']) === 1 && $dia['atencao'][0] === 'Decisão do Copom', 'pontos de atenção: o vazio foi descartado');
$dest = array_filter($dia['noticias']['juros'] ?? [], function ($n) { return $n['destaque']; });
ok(count($dest) === 1, 'só a manchete 1 do tema vale como destaque; o número 999 inexistente foi ignorado');
$urls = array_column(q_all('SELECT url FROM cenario_noticias'), 'url');
ok(count($urls) === count(array_unique($urls)) && count(array_filter($urls, function ($u) { return strpos($u, 'https://exemplo.test/') !== 0 && strpos($u, 'https://ruim.test/') !== 0; })) === 0, 'todos os links vêm da coleta do feed (a IA não cria endereço) e cada manchete tem o seu');
ok(strpos($dia['noticias']['juros'][0]['url'], 'https://exemplo.test/') === 0, 'o link de cada manchete é o do feed');
echo "== filtro de manchetes: fonte, ruido, pais e tema (RN-48a)\n";
$R = function (string $titulo, string $fonte, ?string $dominio, string $tema) { return cenario_manchete_recusada($titulo, $fonte, $dominio, $tema); };
// os tres casos apontados pelo Thiago
ok($R('Manifestantes ocupam praças em Portugal por direito à moradia', 'Agência Brasil', 'agenciabrasil.ebc.com.br', 'imoveis') === 'fora_do_pais', 'protesto por moradia em Portugal (mesmo na Agência Brasil) não entra em tema do Brasil');
ok($R('Ana Castela e Larissa Manoela: compra de casas nos EUA vai além das férias e abre espaço para investimento', 'SB24HORAS', 'sb24horas.com.br', 'imoveis') === 'fonte', 'notícia de celebridades de portal fora da lista de fontes é recusada pela fonte');
$morango = 'A capital do morango que conquistou espaço entre as cidades brasileiras com melhor qualidade de vida';
ok($R($morango, 'Tupi FM', 'tupi.fm', 'imoveis') === 'fonte' && $R($morango, 'Exame', 'exame.com', 'imoveis') === 'ruido', 'qualidade de vida em cidades: recusada pela fonte e, mesmo vinda de fonte aceita, pelo filtro de ruído');
// o que deve continuar entrando
ok($R('Juros do Tesouro voltam a subir e prefixados superam 15%', 'SpaceMoney', 'spacemoney.com.br', 'fiscal') === null, 'juros do Tesouro (SpaceMoney) entra');
ok($R('Copom deve cortar Selic nesta reunião, mas dezembro fica em dúvida, diz economista', 'Money Times', 'moneytimes.com.br', 'juros') === null, 'Selic e Copom entram');
ok($R('Dólar hoje sobe e se aproxima de R$ 5,50; Copom no radar', 'ISTOÉ DINHEIRO', null, 'cambio') === null, 'sem o domínio no feed, a fonte é reconhecida pelo nome');
ok($R('Dólar fecha em alta após decisão do Fed', 'Reuters', 'reuters.com', 'cambio') === null, 'Fed e Wall Street não contam como "outro país": afetam o dólar e a bolsa do Brasil');
ok($R('Manifestantes ocupam praças em Portugal por direito à moradia', 'Agência Brasil', 'agenciabrasil.ebc.com.br', 'internacional') === null, 'o tema internacional aceita notícia do exterior');
ok($R('Preço de imóveis em Madri bate recorde', 'Exame', 'exame.com', 'imoveis') === 'fora_do_pais' && $R('Imóveis em Lisboa disparam e atraem brasileiros', 'Exame', 'exame.com', 'imoveis') === null, 'notícia de outro país só entra se também tratar do Brasil');
ok($R('Prefeitura inaugura nova praça no centro da cidade', 'Exame', 'exame.com', 'politica') === 'fora_do_tema', 'sem nenhum termo de economia, finanças ou governo, é recusada pelo tema');
ok($R('Selic em alta', 'Instagram', null, 'juros') === 'fonte' && $R('Selic em alta', 'Blog qualquer', 'blog.exemplo.com', 'juros') === 'fonte', 'sem fonte reconhecida (nem nome nem domínio): recusada');
ok($R('Bolsa abre em baixa diante de desconfiança fiscal e Midterms nos EUA', 'Metrópoles', 'metropoles.com', 'bolsa') === null, 'bolsa do Brasil que cita eleição nos EUA e tem termo brasileiro (fiscal) entra');
ok($R('Boi a R$ 400: arroba dispara com dólar, China e ciclo pecuário no limite', 'InfoMoney', 'infomoney.com.br', 'cambio') === null, 'preço em reais com a China no título: o R$ mostra que é do Brasil');
ok($R('Midterms nos EUA: apuração começa às 20h, mas resultado pode levar dias', 'InfoMoney', 'infomoney.com.br', 'fiscal') === 'fora_do_pais' && $R('Midterms nos EUA: apuração começa às 20h, mas resultado pode levar dias', 'InfoMoney', 'infomoney.com.br', 'internacional') === 'fora_do_tema', 'eleição americana: fora de tema do Brasil e, no internacional, sem termo de economia');
ok($R('Fundo imobiliário anuncia dividendo recorde e vai à Justiça para revisar aluguéis', 'Money Times', 'moneytimes.com.br', 'imoveis') === null && $R('Preço do imóvel sobe 8% em 12 meses no Brasil', 'Exame', 'exame.com', 'imoveis') === null, '"imóvel" no singular e no plural é reconhecido como tema');
ok($R('Escritórios têm maior potencial de valorização, diz executivo', 'Estadão Blue Studio', 'estadao.com.br', 'imoveis') === 'fonte', 'conteúdo patrocinado (Blue Studio) é recusado mesmo no domínio de uma fonte aceita');
ok($R('Stevie Wonder disputa imóvel de US$ 2 milhões com ex-noiva', 'Times Brasil | CNBC', 'cnbc.com', 'imoveis') === 'ruido' && $R('Mansão onde Elvis viveu em Beverly Hills é colocada à venda', 'CNN Brasil', 'cnnbrasil.com.br', 'imoveis') === 'ruido', 'fofoca e mansão de celebridade, mesmo de fonte aceita, são recusadas');
ok($R('Lula precisa encontrar dupla como Palocci e Meirelles e empoderar, aponta Kassab', 'InfoMoney', 'infomoney.com.br', 'politica') === null, 'política econômica com o presidente no título entra');
ok(cenario_fonte_confiavel('x', 'noticias.valor.globo.com') && !cenario_fonte_confiavel('x', 'valor.globo.com.malicioso.com') && !cenario_fonte_confiavel('x', 'fakeexame.com'), 'subdomínio de fonte aceita vale, mas domínio parecido (sufixo falso) não');

$n1 = (int) q_val('SELECT COUNT(*) FROM cenario_noticias');
cenario_gerar_dia();
igual((int) q_val('SELECT COUNT(*) FROM cenario_noticias'), $n1, 'rodar de novo no mesmo dia refaz sem duplicar manchetes', 0);
igual((int) q_val('SELECT COUNT(*) FROM cenario_diario'), 1, 'uma linha por dia', 0);
$GLOBALS['IA_MODO'] = 'falha';
$dMantida = cenario_gerar_dia();
$diaMantido = cenario_dia();
ok($dMantida['origem'] === 'mantida' && $diaMantido['origem'] === 'ia' && strpos((string) $dMantida['erro_ia'], 'IA fora do ar') !== false, 'IA falha ao refazer o dia: a leitura por IA já gravada é mantida e o motivo da falha é informado');
q('DELETE FROM cenario_diario WHERE data = ?', [date('Y-m-d')]);
$d2 = cenario_gerar_dia();
$dia2 = cenario_dia();
ok($d2['origem'] === 'sem_ia' && $dia2['origem'] === 'sem_ia' && strpos($dia2['resumo'], 'não ficou disponível') !== false, 'IA fora do ar: o dia é gravado só com manchetes e indicadores');
ok(risco_nivel_temas($dia2, ['fiscal']) === null, 'sem leitura da IA, o nível de risco externo fica sem dado (não vira zero)');
$GLOBALS['IA_MODO'] = 'ok';
cenario_gerar_dia();

echo "== fechamento mensal (passado, presente e futuro)\n";
$mesPassado = date('Y-m', strtotime('first day of -1 month'));
cenario_gerar_dia($mesPassado . '-15');
ok(cenario_meses_pendentes() === [$mesPassado], 'o mês fechado com leitura aparece como pendente (o mês atual não)');
$m = cenario_gerar_mensal($mesPassado);
ok($m !== null && $m['origem'] === 'ia', 'fechamento gerado com a IA');
$mens = cenario_mensais(3);
ok(count($mens) === 1 && strpos($mens[0]['perspectiva'], 'cortes graduais') !== false && $mens[0]['resumo'] !== '', 'guarda o que aconteceu e a perspectiva para os próximos meses');
ok(cenario_gerar_mensal($mesPassado) === null && cenario_meses_pendentes() === [], 'não refaz um mês já fechado, a menos que seja forçado');

echo "== risco consolidado\n";
$cdb = q_insert('INSERT INTO investimentos (perfil_id, nome, tipo, instituicao, indexador, percentual_cdi, tributacao) VALUES (?,?,?,?,?,?,?)', [perfil_id(), 'CDB DI', 'renda_fixa', 'Banco B', 'cdi', 100, 'nenhuma']);
rf_aplicar(0, $cdb, 100000, date('Y-m-d', strtotime('-60 day')), true);
$rc = risco_consolidar();
$chaves = array_column($rc['dimensoes'], 'chave');
ok($chaves === ['juros', 'inflacao', 'moeda', 'concentracao', 'liquidez', 'externo'], 'seis dimensões do risco');
$niv = array_filter(array_column($rc['dimensoes'], 'nivel'), function ($n) { return $n !== null; });
ok(count($niv) === 6 && min($niv) >= 1 && max($niv) <= 5, 'todos os níveis entre 1 e 5 com a leitura do dia');
$dim = function (string $k) use ($rc) { return array_values(array_filter($rc['dimensoes'], function ($d) use ($k) { return $d['chave'] === $k; }))[0]; };
ok($rc['nivel_geral'] >= 1 && $rc['nivel_geral'] <= 5, 'nível geral entre 1 e 5');
ok($dim('juros')['nivel'] >= 2 && strpos($dim('juros')['motivo'], 'Focus') !== false, 'juros: carteira 100% CDI com Focus projetando queda da Selic sobe o nível e cita o Focus');
ok(strpos($dim('inflacao')['motivo'], 'juro real líquido') !== false, 'inflação: o motivo traz o juro real líquido');
ok(strpos($dim('inflacao')['motivo'], 'não entrou na conta') !== false, 'inflação: avisa que a cesta pessoal não entrou por cobertura baixa');
igual($rc['exposicao']['usd_pct_patrimonio'], 0, 'sem investimento em dólar: exposição cambial zero');
ok($dim('moeda')['nivel'] >= 3, 'moeda: 100% em reais é concentração em uma moeda (nível 3 ou mais)');
ok($rc['dolarizacao']['pontos'] >= 2 && $rc['dolarizacao']['sinal'] === 'avaliar_aumentar', 'dolarização: câmbio esperado acima, risco fiscal/político/câmbio alto e 0% em dólar levam a "avaliar aumentar"');
$fatores = implode(' | ', array_column($rc['dolarizacao']['fatores'], 'texto'));
ok(strpos($fatores, 'Focus') !== false && strpos($fatores, 'depende do real') !== false, 'todos os fatores do sinal aparecem com o motivo');
ok(count($rc['dolarizacao']['como']) === 2, 'explica as duas formas: comprar dólar e investir no exterior');

echo "== dolarizacao: exposicao e sinal contrario\n";
$usd = q_insert('INSERT INTO investimentos (perfil_id, nome, tipo, instituicao, indexador, tributacao, moeda) VALUES (?,?,?,?,?,?,?)', [perfil_id(), 'ETF exterior', 'fundos', 'Corretora', 'manual', 'nenhuma', 'USD']);
rf_aplicar(0, $usd, 60000, date('Y-m-d', strtotime('-60 day')), true);
$rc2 = risco_consolidar();
$esperado = round(60000 / ($rc2['exposicao']['patrimonio']) * 100, 1);
igual($rc2['exposicao']['usd_pct_patrimonio'], $esperado, 'exposição em dólar = investimento em USD ÷ patrimônio', 0.2);
ok($rc2['exposicao']['usd_pct_patrimonio'] > 30, 'passa de 30% do patrimônio');
ok($rc2['dolarizacao']['pontos'] < $rc['dolarizacao']['pontos'], 'com parte relevante já em dólar, o sinal perde pontos');
$GLOBALS['IA_MODO'] = 'falha';
q('DELETE FROM cenario_diario WHERE data = ?', [date('Y-m-d')]);
cenario_gerar_dia();
$rc3 = risco_consolidar();
$ext = array_values(array_filter($rc3['dimensoes'], function ($d) { return $d['chave'] === 'externo'; }))[0];
ok($ext['nivel'] === null && $rc3['nivel_geral'] >= 1, 'sem leitura por IA, a dimensão externa fica sem nível e o geral usa só as outras');
$GLOBALS['IA_MODO'] = 'ok';
cenario_gerar_dia();

echo "== snapshots e historico\n";
$s = risco_snapshot_salvar();
ok($s['perfil_id'] === perfil_id() && $s['nivel_geral'] >= 1, 'foto de risco gravada');
risco_snapshot_salvar();
igual((int) q_val('SELECT COUNT(*) FROM risco_snapshots WHERE perfil_id = ?', [perfil_id()]), 1, 'uma foto por dia por perfil (refaz no mesmo dia)', 0);
risco_snapshot_salvar($mesPassado . '-20');
$h = risco_historico();
ok(count($h) === 2 && $h[0]['data'] === date('Y-m-d') && substr($h[1]['data'], 0, 7) === $mesPassado, 'histórico: a última foto de cada mês, da mais nova para a mais antiga');
ok(count($h[0]['dimensoes']) === 6 && $h[0]['rotulo'] !== '', 'cada foto guarda as dimensões e o rótulo do nível');
$todos = risco_snapshot_todos();
ok(count($todos) === 1 && isset($todos[0]['nivel_geral']), 'a rotina grava a foto de cada perfil de usuário ativo');
ok($_SESSION['perfil_id'] === perfil_id(), 'e restaura a sessão depois');

echo "== rotina completa isolada por etapa\n";
q('DELETE FROM cenario_diario WHERE data = ?', [date('Y-m-d')]);
$GLOBALS['IBGE_FALHA'] = true;
$t = cenario_atualizar_tudo(true);
ok(isset($t['dia']['origem']) && isset($t['riscos']) && count($t['erros']) === 1 && strpos($t['erros'][0], 'IBGE') !== false, 'IBGE fora do ar: a rotina segue com a leitura do dia e o risco, e registra só esse erro');
$GLOBALS['IBGE_FALHA'] = false;
$t2 = cenario_atualizar_tudo(false);
ok($t2['dia']['origem'] === 'ja_existia', 'sem forçar, não refaz a leitura do dia que já tem IA');
$GLOBALS['IA_MODO'] = 'falha';
q('DELETE FROM cenario_diario WHERE data = ?', [date('Y-m-d')]);
$t3 = cenario_atualizar_tudo(false);
ok($t3['dia']['origem'] === 'sem_ia' && count($t3['erros']) === 1 && strpos($t3['erros'][0], 'Leitura por IA') !== false, 'IA fora do ar: o dia fica só com manchetes e o erro da IA é informado');
$GLOBALS['IA_MODO'] = 'ok';
$t4 = cenario_atualizar_tudo(false);
ok($t4['dia']['origem'] === 'ia' && !$t4['erros'], 'na rodada seguinte, sem forçar, a leitura por IA é refeita porque o dia estava só com manchetes');

echo "\n" . ($falhas ? "$falhas FALHA(S)\n" : "TODOS OS TESTES PASSARAM\n");
exit($falhas ? 1 : 0);
