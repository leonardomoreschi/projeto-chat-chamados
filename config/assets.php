<?php
declare(strict_types=1);

/**
 * URL versionada de um asset local (JS/CSS servido de /public).
 *
 * O nginx entrega /assets com `Cache-Control: public, immutable` e um ano de
 * validade (docker/nginx/default.conf). Com `immutable` o navegador nem
 * revalida no F5, então alterar um arquivo sem alterar a URL deixa o usuário
 * preso na versão antiga até o cache expirar. O sufixo `?v={mtime}` muda a URL
 * a cada alteração do arquivo e invalida o cache sozinho — mantendo o cache
 * longo, que é o comportamento correto para uma URL versionada.
 *
 * Arquivo inexistente devolve o caminho original, sem quebrar a página.
 */
function asset(string $caminho): string
{
    static $versoes = [];

    if (isset($versoes[$caminho])) {
        return $versoes[$caminho];
    }

    $arquivo = __DIR__ . '/../public' . $caminho;
    $mtime = is_file($arquivo) ? filemtime($arquivo) : false;

    return $versoes[$caminho] = $mtime === false
        ? $caminho
        : $caminho . '?v=' . $mtime;
}
