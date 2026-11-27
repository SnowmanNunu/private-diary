<?php

namespace App\Services;

use App\Models\Entry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class LastYearReview
{
    public function today(): ?Entry
    {
        return $this->for(now()->toDateString());
    }

    public function for(string $date): ?Entry
    {
        $target = Carbon::parse($date)->subYear()->toDateString();

        return Entry::where('user_id', Auth::id())
            ->where('entry_date', $target)
            ->first();
    }
}
