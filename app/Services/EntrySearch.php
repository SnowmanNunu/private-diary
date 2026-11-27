<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EntrySearch
{
    public function ids(string $query): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        if (mb_strlen($query) < 3) {
            return $this->substringIds($query);
        }

        $phrase = '"'.str_replace('"', '""', $query).'"';

        return collect(
            DB::select(
                'SELECT entry_id FROM entries_fts WHERE entries_fts MATCH ?',
                [$phrase],
            )
        )->pluck('entry_id')->filter()->values();
    }

    private function substringIds(string $query): Collection
    {
        $sql = <<<'SQL'
            instr(lower(coalesce(title, '')), lower(?)) > 0
            OR instr(lower(coalesce(body, '')), lower(?)) > 0
            SQL;

        return DB::table('entries')->whereRaw($sql, [$query, $query])->pluck('id');
    }
}
