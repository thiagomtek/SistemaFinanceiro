<?php
declare(strict_types=1);

/** Leitor minimalista de .xlsx (ZipArchive + SimpleXML), sem dependencias externas. */
final class XlsxReader
{
    /** Limites contra planilhas "bomba" (um .xlsx pequeno pode expandir para gigabytes de XML). */
    private const MAX_BYTES_XML = 20 * 1024 * 1024; // o XML vira ~5x em memoria (limite do PHP: 256 MB)
    private const MAX_LINHAS = 50000;
    private const MAX_COLUNAS = 200;

    /** @return array<string, array<int, array<int, string>>> nome da aba => linhas => colunas */
    public static function ler(string $arquivo): array
    {
        $zip = new ZipArchive();
        if ($zip->open($arquivo) !== true) {
            throw new RuntimeException('Arquivo .xlsx inválido ou corrompido.');
        }
        try {
            $compartilhadas = self::stringsCompartilhadas($zip);
            $abas = self::abas($zip);
            $out = [];
            foreach ($abas as $nome => $caminho) {
                self::conferirTamanho($zip, $caminho);
                $xml = $zip->getFromName($caminho);
                $out[$nome] = $xml === false ? [] : self::linhas($xml, $compartilhadas);
            }
            return $out;
        } finally {
            $zip->close();
        }
    }

    private static function conferirTamanho(ZipArchive $zip, string $nome): void
    {
        $st = $zip->statName($nome);
        if ($st !== false && $st['size'] > self::MAX_BYTES_XML) {
            throw new RuntimeException('A planilha é grande demais para importar (divida em arquivos menores).');
        }
    }

    private static function xml(string $conteudo): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($conteudo, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        if (!$x) {
            throw new RuntimeException('Conteúdo XML da planilha inválido.');
        }
        return $x;
    }

    private static function stringsCompartilhadas(ZipArchive $zip): array
    {
        self::conferirTamanho($zip, 'xl/sharedStrings.xml');
        $c = $zip->getFromName('xl/sharedStrings.xml');
        if ($c === false) {
            return [];
        }
        $out = [];
        foreach (self::xml($c)->si as $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string) $si->t;
            } else {
                foreach ($si->r as $r) {
                    $t .= (string) $r->t;
                }
            }
            $out[] = $t;
        }
        return $out;
    }

    /** @return array<string,string> nome => caminho do xml da aba */
    private static function abas(ZipArchive $zip): array
    {
        $wb = self::xml((string) $zip->getFromName('xl/workbook.xml'));
        $relXml = self::xml((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        $rels = [];
        foreach ($relXml->Relationship as $r) {
            $alvo = (string) $r['Target'];
            $rels[(string) $r['Id']] = ltrim(str_starts_with($alvo, '/') ? $alvo : 'xl/' . $alvo, '/');
        }
        $out = [];
        foreach ($wb->sheets->sheet as $s) {
            $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (isset($rels[$rid])) {
                $out[(string) $s['name']] = $rels[$rid];
            }
        }
        return $out;
    }

    private static function linhas(string $conteudo, array $compartilhadas): array
    {
        $x = self::xml($conteudo);
        $linhas = [];
        foreach ($x->sheetData->row ?? [] as $row) {
            if (count($linhas) >= self::MAX_LINHAS) {
                throw new RuntimeException('A planilha tem linhas demais (limite de ' . self::MAX_LINHAS . ').');
            }
            $celulas = [];
            $pos = 0;
            foreach ($row->c as $c) {
                if (isset($c['r'])) {
                    $pos = self::colunaIndice((string) $c['r']);
                }
                if ($pos >= self::MAX_COLUNAS) {
                    continue; // coluna absurda (ex.: XFD1): ignorada para nao alocar milhares de celulas vazias
                }
                $tipo = (string) $c['t'];
                if ($tipo === 's') {
                    $v = $compartilhadas[(int) $c->v] ?? '';
                } elseif ($tipo === 'inlineStr') {
                    $v = (string) $c->is->t;
                } else {
                    $v = (string) $c->v;
                }
                $celulas[$pos] = $v;
                $pos++;
            }
            if ($celulas) {
                $max = max(array_keys($celulas));
                $linha = [];
                for ($i = 0; $i <= $max; $i++) {
                    $linha[] = $celulas[$i] ?? '';
                }
                $linhas[] = $linha;
            }
        }
        return $linhas;
    }

    private static function colunaIndice(string $ref): int
    {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $n = 0;
        foreach (str_split($m[1] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}
