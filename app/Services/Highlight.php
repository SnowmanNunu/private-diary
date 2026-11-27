<?php

namespace App\Services;

class Highlight
{
    /**
     * 高亮文本中的关键词。先切分再逐段转义，
     * 避免关键词命中 HTML 实体或原始标签造成注入。
     */
    public function text(?string $text, string $keyword): string
    {
        $text = $text ?? '';
        $keyword = trim($keyword);

        if ($keyword === '') {
            return e($text);
        }

        $parts = preg_split(
            '/('.preg_quote($keyword, '/').')/iu',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if ($parts === false || count($parts) < 3) {
            return e($text);
        }

        $html = '';

        foreach ($parts as $index => $part) {
            $html .= $index % 2 === 1
                ? '<mark>'.e($part).'</mark>'
                : e($part);
        }

        return $html;
    }
}
