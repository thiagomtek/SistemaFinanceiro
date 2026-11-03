<?php
declare(strict_types=1);

/* AnexoService: arquivos anexados a lancamentos (comprovantes). Guardados fora do DocumentRoot, por perfil. */

const ANEXO_TIPOS = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'txt' => 'text/plain', 'csv' => 'text/csv',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
const ANEXO_MAX_BYTES = 5 * 1024 * 1024;
const ANEXO_MAX_TOTAL_BYTES = 200 * 1024 * 1024; // por perfil

function anexos_dir(): string
{
    return UPLOAD_DIR . '/anexos/' . perfil_id();
}

/** Anexa o arquivo enviado ($_FILES[...]) ao lancamento. Retorna o ID do anexo. */
function anexar_arquivo(int $lancamento_id, array $f): int
{
    if (!q_val('SELECT id FROM lancamentos WHERE id = ? AND perfil_id = ?', [$lancamento_id, perfil_id()])) {
        throw new InvalidArgumentException('Lançamento inválido para o anexo.');
    }
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Não foi possível receber o anexo.');
    }
    if ($f['size'] > ANEXO_MAX_BYTES) {
        throw new InvalidArgumentException('Anexo muito grande (limite de 5 MB).');
    }
    if ((int) q_val('SELECT COALESCE(SUM(tamanho), 0) FROM anexos WHERE perfil_id = ?', [perfil_id()]) + (int) $f['size'] > ANEXO_MAX_TOTAL_BYTES) {
        throw new InvalidArgumentException('O limite de ' . (ANEXO_MAX_TOTAL_BYTES / 1048576) . ' MB de anexos foi atingido. Exclua anexos antigos.');
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!isset(ANEXO_TIPOS[$ext])) {
        throw new InvalidArgumentException('Tipo de anexo não permitido. Use PDF, imagem, TXT, CSV, XLSX ou DOCX.');
    }
    $dir = anexos_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a pasta de anexos.');
    }
    $arquivo = bin2hex(random_bytes(16)) . '.' . $ext;
    $ok = is_uploaded_file($f['tmp_name']) ? move_uploaded_file($f['tmp_name'], $dir . '/' . $arquivo) : (PHP_SAPI === 'cli' && copy($f['tmp_name'], $dir . '/' . $arquivo));
    if (!$ok) {
        throw new RuntimeException('Falha ao gravar o anexo.');
    }
    return q_insert(
        'INSERT INTO anexos (perfil_id, lancamento_id, nome, arquivo, mime, tamanho) VALUES (?,?,?,?,?,?)',
        [perfil_id(), $lancamento_id, mb_substr(basename((string) $f['name']), 0, 160), $arquivo, ANEXO_TIPOS[$ext], (int) $f['size']]
    );
}

/** @return array<int, array> lancamento_id => lista de anexos */
function anexos_de_lancamentos(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $out = [];
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    foreach (q_all("SELECT id, lancamento_id, nome, tamanho FROM anexos WHERE perfil_id = ? AND lancamento_id IN ($marcas) ORDER BY id", array_merge([perfil_id()], $ids)) as $a) {
        $out[(int) $a['lancamento_id']][] = $a;
    }
    return $out;
}

function anexo_dados(int $id): ?array
{
    return q_one('SELECT * FROM anexos WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
}

function anexo_excluir(int $id): bool
{
    $a = anexo_dados($id);
    if (!$a) {
        return false;
    }
    @unlink(anexos_dir() . '/' . $a['arquivo']);
    q('DELETE FROM anexos WHERE id = ? AND perfil_id = ?', [$id, perfil_id()]);
    return true;
}

/** Apaga os arquivos dos anexos de lancamentos que serao excluidos (as linhas somem por cascata). */
function anexos_remover_arquivos(array $lancamentoIds): void
{
    foreach (anexos_de_lancamentos($lancamentoIds) as $lista) {
        foreach ($lista as $a) {
            $r = anexo_dados((int) $a['id']);
            if ($r) {
                @unlink(anexos_dir() . '/' . $r['arquivo']);
            }
        }
    }
}
