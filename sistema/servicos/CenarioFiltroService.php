<?php
declare(strict_types=1);

/* =========================================================================
   CenarioFiltroService: so entra no cenario a manchete que passa por quatro filtros, nesta ordem (RN-48a):
   1) fonte: veiculo da lista de fontes confiaveis (por nome ou pelo dominio informado no feed);
   2) ruido: entretenimento, celebridades, esporte, turismo e similares;
   3) pais: nos temas do Brasil, noticia sobre outro pais so entra se tambem tratar do Brasil (o tema "internacional" aceita o exterior);
   4) tema: o titulo precisa ter ao menos um termo de economia, financas, governo ou mercado.
   Os filtros usam o titulo normalizado (minusculo e sem acento). Servem de primeira barreira: a IA ainda pode descartar o resto.
   ========================================================================= */

/** Dominios (e subdominios) de veiculos aceitos. Edite aqui para incluir ou tirar um veiculo. */
const CENARIO_FONTES_DOMINIOS = [
    'valor.globo.com', 'infomoney.com.br', 'exame.com', 'estadao.com.br', 'folha.uol.com.br', 'oglobo.globo.com', 'g1.globo.com', 'epocanegocios.globo.com',
    'cnnbrasil.com.br', 'agenciabrasil.ebc.com.br', 'reuters.com', 'bloomberglinea.com.br', 'bloomberg.com', 'moneytimes.com.br', 'br.investing.com',
    'bmcnews.com.br', 'forbes.com.br', 'veja.abril.com.br', 'istoedinheiro.com.br', 'seudinheiro.com', 'suno.com.br', 'spacemoney.com.br', 'uol.com.br',
    'gauchazh.clicrbs.com.br', 'gazetadopovo.com.br', 'correiobraziliense.com.br', 'metropoles.com', 'poder360.com.br', 'bbc.com', 'dw.com', 'cnbc.com', 'ft.com',
    'cartacapital.com.br', 'investnews.com.br', 'sbtnews.sbt.com.br', 'gov.br', 'bcb.gov.br', 'ibge.gov.br', 'senado.leg.br', 'camara.leg.br', 'tesourotransparente.gov.br', 'b3.com.br',
];

/** Nomes de veiculos aceitos (normalizados), para quando o feed nao informa o dominio. */
const CENARIO_FONTES_NOMES = [
    'valor economico', 'valor investe', 'valor globo', 'infomoney', 'exame', 'estadao', 'broadcast', 'folha de s.paulo', 'folha', 'o globo', 'g1', 'epoca negocios',
    'cnn brasil', 'agencia brasil', 'agencia senado', 'agencia camara', 'agencia ibge de noticias', 'banco central do brasil', 'tesouro nacional', 'reuters',
    'bloomberg linea', 'bloomberg', 'money times', 'investing.com brasil', 'bm&c', 'bm&c news', 'forbes brasil', 'veja', 'istoe dinheiro', 'seu dinheiro', 'suno noticias',
    'spacemoney', 'uol', 'gzh', 'gazeta do povo', 'correio braziliense', 'metropoles', 'poder360', 'bbc news brasil', 'dw', 'times brasil | cnbc', 'cnbc', 'financial times',
    'cartacapital', 'investnews', 'sbt news',
];

/** Termos de outros paises (so valem nos temas do Brasil). */
const CENARIO_TERMOS_FORA = '\b(portugal|portugues(?:a|es)?|lisboa|espanha|madri|madrid|argentin\w*|chile|mexic\w*|estados unidos|eua|miami|orlando|florida|nova york|new york|europa|europeu\w*|reino unido|londres|franca|paris|alemanha|italia|canada|china|chines\w*|japao|india|russia|ucrania|israel|gaza|australia)\b'; // Fed, Wall Street e similares ficam de fora: afetam o dolar e a bolsa do Brasil

/** Termos que mostram que a noticia tambem trata do Brasil. */
const CENARIO_TERMOS_BR = '\b(brasil|brasileir\w*|selic|copom|ibovespa|ipca|inpc|igp-?m|banco central|b3|tesouro direto|tesouro nacional|reais|governo federal|congresso|senado|camara dos deputados|lula|haddad|galipolo|ministerio da fazenda|petrobras|bndes|receita federal|fipezap|focus|cdi|stf|fiscal|imposto seletivo|bolsa (?:abre|cai|sobe|fecha|opera)|dolar (?:hoje|abre|fecha|sobe|cai))\b';

/** Entretenimento, celebridades, esporte, turismo e similares. */
const CENARIO_TERMOS_RUIDO = '\b(celebridades?|famos[oa]s?|influenciador\w*|influencer\w*|cantor\w*|atriz|atores|bbb|novelas?|fofocas?|horoscopo|signos?|receitas? de|qualidade de vida|jogo do bicho|mega-?sena|loterias?|futebol|campeonato|copa do mundo|olimpiadas?|esportes?|turismo|viagens?|ferias|festas?|casamento|namoro|separacao|polemica|reality|morango|gossip|mansao|mansoes|noiv[oa]s?|hollywood|beverly hills|astros?|estrelas?)\b';

/** Termos de economia, financas, governo e mercado (o titulo precisa ter ao menos um). */
const CENARIO_TERMOS_ECON = '\b(juros?|selic|copom|inflacao|ipca|inpc|igp-?m|precos?|fiscal|divida|orcamento|tesouro|arcabouco|deficit|superavit|impostos?|tribut\w*|reforma\w*|cambio|dolar|euro|moeda|bolsa|ibovespa|acoes|b3|mercado|imobili\w*|imove(?:l|is)|moradia|valorizacao|aluguel|alugueis|financiamento|credito|fii|fundos?|economia|economic\w*|pib|invest\w*|renda fixa|cdb|cdi|lci|lca|bancos?|focus|taxas?|rendimentos?|poupanca|previdencia|salario|emprego|desemprego|consumo|varejo|industria|petroleo|combustiv\w*|energia|gastos?|arrecadacao|receita|governo|congresso|senado|camara|ministro|stf|eleic\w*|medida provisoria|politica\w*|lucro|balanco|commodities|economist\w*|cpi|monetari\w*|custos?|custou|lula|haddad|galipolo|planalto|dividendos?|rentabilidade|exportac\w*|importac\w*|tarifa\w*|fed|bc)\b';

/** Host de uma URL sem "www." (ou null). */
function cenario_host(?string $url): ?string
{
    $h = $url ? parse_url($url, PHP_URL_HOST) : null;
    return is_string($h) && $h !== '' ? preg_replace('/^www\./', '', mb_strtolower($h)) : null;
}

/** O veiculo esta na lista de fontes confiaveis (pelo dominio do feed ou, na falta dele, pelo nome)? */
function cenario_fonte_confiavel(string $nome, ?string $dominio): bool
{
    if (preg_match('/(blue studio|brand studio|branded|conteudo patrocinado|publieditorial|publicidade|patrocinad)/', inflacao_normalizar($nome))) {
        return false; // conteudo pago de um veiculo aceito nao e noticia
    }
    if ($dominio !== null) {
        foreach (CENARIO_FONTES_DOMINIOS as $d) {
            if ($dominio === $d || substr($dominio, -strlen('.' . $d)) === '.' . $d) {
                return true;
            }
        }
    }
    return in_array(inflacao_normalizar($nome), CENARIO_FONTES_NOMES, true);
}

/**
 * Decide se a manchete entra. Devolve null quando aceita, ou o motivo da recusa: 'fonte', 'ruido', 'fora_do_pais' ou 'fora_do_tema'.
 */
function cenario_manchete_recusada(string $titulo, string $fonte, ?string $dominio, string $tema): ?string
{
    if (!cenario_fonte_confiavel($fonte, $dominio)) {
        return 'fonte';
    }
    $t = inflacao_normalizar($titulo);
    if (preg_match('/' . CENARIO_TERMOS_RUIDO . '/u', $t)) {
        return 'ruido';
    }
    $fora = preg_match('/' . CENARIO_TERMOS_FORA . '/u', $t) || strpos($t, 'us$') !== false; // dolar americano: sinal de assunto estrangeiro
    $br = preg_match('/' . CENARIO_TERMOS_BR . '/u', $t) || strpos($t, 'r$') !== false; // real: sinal de assunto brasileiro
    if ($tema !== 'internacional' && $fora && !$br) {
        return 'fora_do_pais';
    }
    if (!preg_match('/' . CENARIO_TERMOS_ECON . '/u', $t)) {
        return 'fora_do_tema';
    }
    return null;
}
