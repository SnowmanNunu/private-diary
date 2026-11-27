<?php

namespace App\Services;

use League\CommonMark\GithubFlavoredMarkdownConverter;

class Markdown
{
    public function render(?string $source): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return (string) $converter->convert($source ?? '');
    }
}
