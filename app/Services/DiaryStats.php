<?php

namespace App\Services;

use App\Models\Entry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class DiaryStats
{
    public function years(): array
    {
        return Entry::where('user_id', Auth::id())
            ->selectRaw('strftime("%Y", entry_date) as year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->all();
    }

    public function forYear(int $year): array
    {
        $entries = Entry::where('user_id', Auth::id())
            ->whereYear('entry_date', $year)
            ->with('tags')
            ->orderBy('entry_date')
            ->get();

        $byDate = $entries->keyBy(fn (Entry $entry): string => $entry->entry_date->format('Y-m-d'));

        $dates = $entries->map(fn (Entry $entry): string => $entry->entry_date->format('Y-m-d'))->all();

        $tagCounts = [];

        foreach ($entries as $entry) {
            foreach ($entry->tags as $tag) {
                $tagCounts[$tag->name] = ($tagCounts[$tag->name] ?? 0) + 1;
            }
        }

        arsort($tagCounts);

        $monthly = array_fill(1, 12, 0);

        foreach ($entries as $entry) {
            $monthly[(int) $entry->entry_date->format('n')]++;
        }

        return [
            'year' => $year,
            'years' => $this->years(),
            'total' => $entries->count(),
            'words' => $entries->sum(fn (Entry $entry): int => mb_strlen(preg_replace('/\s+/u', '', (string) $entry->body) ?? '')),
            'days' => count($dates),
            'longestStreak' => $this->longestStreak($dates),
            'currentStreak' => $this->currentStreak($dates),
            'monthly' => $monthly,
            'topTags' => array_slice($tagCounts, 0, 10, true),
            'heatmap' => $this->heatmap($year, $byDate),
        ];
    }

    private function longestStreak(array $dates): int
    {
        if ($dates === []) {
            return 0;
        }

        $unique = array_values(array_unique($dates));
        sort($unique);

        $longest = 1;
        $current = 1;

        for ($i = 1; $i < count($unique); $i++) {
            $previous = Carbon::createFromFormat('!Y-m-d', $unique[$i - 1]);
            $currentDate = Carbon::createFromFormat('!Y-m-d', $unique[$i]);

            $current = $previous->diffInDays($currentDate) === 1 ? $current + 1 : 1;
            $longest = max($longest, $current);
        }

        return $longest;
    }

    private function currentStreak(array $dates): int
    {
        if ($dates === []) {
            return 0;
        }

        $days = array_map(fn (string $date): string => Carbon::createFromFormat('!Y-m-d', $date)->toDateString(), $dates);
        sort($days);
        $days = array_reverse(array_values(array_unique($days)));

        $cursor = Carbon::today();

        if ($days[0] !== $cursor->toDateString()) {
            $cursor->subDay();

            if ($days[0] !== $cursor->toDateString()) {
                return 0;
            }
        }

        $streak = 0;

        foreach ($days as $day) {
            if ($day !== $cursor->toDateString()) {
                break;
            }

            $streak++;
            $cursor->subDay();
        }

        return $streak;
    }

    private function heatmap(int $year, $byDate): array
    {
        $start = Carbon::createFromFormat('!Y-m-d', $year.'-01-01')->startOfWeek(Carbon::MONDAY);
        $end = Carbon::createFromFormat('!Y-m-d', $year.'-12-31');
        $cursor = $start->copy();
        $weeks = [];

        while ($cursor->lte($end)) {
            $days = [];

            for ($day = 1; $day <= 7; $day++) {
                $date = $cursor->toDateString();
                $entry = $byDate->get($date);
                $count = $entry ? 1 : 0;

                $days[] = [
                    'date' => $date,
                    'count' => $count,
                    'level' => $count === 0 ? 0 : min(4, 1 + (int) floor(mb_strlen((string) $entry->body) / 400)),
                    'outside' => $cursor->year !== $year,
                    'future' => $cursor->gt(Carbon::today()),
                ];

                $cursor->addDay();
            }

            $weeks[] = $days;
        }

        return $weeks;
    }
}
