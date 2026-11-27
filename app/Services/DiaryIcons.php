<?php

namespace App\Services;

/**
 * 心情与天气的内联 SVG 图标。
 * 图标全部内置，不依赖 emoji 字体或外部 CDN。
 */
class DiaryIcons
{
    /** @var array<string, string> */
    private const MOODS = [
        'happy' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c.9 1.6 2 2.4 3.5 2.4s2.6-.8 3.5-2.4"/><path d="M9 9.5h.01M15 9.5h.01"/>',
        'calm' => '<circle cx="12" cy="12" r="9"/><path d="M9 15.2h6"/><path d="M9 9.5h.01M15 9.5h.01"/>',
        'excited' => '<circle cx="12" cy="12" r="9"/><path d="m7.5 9.5 2.5 1.5-2.5 1.5M16.5 9.5 14 11l2.5 1.5"/><path d="M8.5 14c1 2 2.1 3 3.5 3s2.5-1 3.5-3z"/>',
        'grateful' => '<path d="M12 20.5S4.8 15.7 4.8 10.4A4.2 4.2 0 0 1 12 8a4.2 4.2 0 0 1 7.2 2.4c0 5.3-7.2 10.1-7.2 10.1Z"/>',
        'sad' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 16c.9-1.6 2-2.4 3.5-2.4s2.6.8 3.5 2.4"/><path d="M9 9.5h.01M15 9.5h.01"/>',
        'anxious' => '<circle cx="12" cy="12" r="9"/><path d="m8 8.5 2.5 1M16 8.5 13.5 9.5"/><path d="M9 15.5c1-1.5 2-1.5 3 0s2 1.5 3 0"/>',
        'tired' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 10h3M12.5 10h3"/><path d="M9 15.5h6"/>',
    ];

    /** @var array<string, string> */
    private const WEATHER = [
        'sunny' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'cloudy' => '<path d="M7.5 18h9.2a3.9 3.9 0 0 0 .3-7.8A5.4 5.4 0 0 0 6.8 9.5 4.3 4.3 0 0 0 7.5 18Z"/><path d="M4 6.5c.8-.7 1.6-.7 2.4 0"/>',
        'overcast' => '<path d="M7.5 18h9.2a3.9 3.9 0 0 0 .3-7.8A5.4 5.4 0 0 0 6.8 9.5 4.3 4.3 0 0 0 7.5 18Z"/><path d="M3.5 13.5h16"/>',
        'rainy' => '<path d="M7 15h10a3.7 3.7 0 0 0 .2-7.4A5.2 5.2 0 0 0 6.4 6.8 4 4 0 0 0 7 15Z"/><path d="m8.5 17.5-1 2.5M12 17.5l-1 2.5M15.5 17.5l-1 2.5"/>',
        'snowy' => '<path d="M7 15h10a3.7 3.7 0 0 0 .2-7.4A5.2 5.2 0 0 0 6.4 6.8 4 4 0 0 0 7 15Z"/><path d="M9 19.5h.01M13 19.5h.01M17 19.5h.01M9 21.5h.01M13 21.5h.01M17 21.5h.01"/>',
        'windy' => '<path d="M3 8h9a2.5 2.5 0 1 0-2.5-2.5M3 12h14a2.5 2.5 0 1 1-2.5 2.5M3 16h7"/>',
        'foggy' => '<path d="M7 13h10a3.7 3.7 0 0 0 .2-7.4A5.2 5.2 0 0 0 6.4 4.8 4 4 0 0 0 7 13Z"/><path d="M4 17h16M6 20.5h12"/>',
    ];

    public function mood(?string $key): string
    {
        return $this->svg(self::MOODS[$key ?? ''] ?? '');
    }

    public function weather(?string $key): string
    {
        return $this->svg(self::WEATHER[$key ?? ''] ?? '');
    }

    private function svg(string $paths): string
    {
        if ($paths === '') {
            return '';
        }

        return '<svg class="diary-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" '
            .'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            .$paths.'</svg>';
    }
}
