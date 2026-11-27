<?php

namespace App\Http\Controllers;

use App\Services\DiaryStats;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function index(Request $request, DiaryStats $stats)
    {
        $raw = (string) $request->query('year', (string) now()->year);

        abort_unless(preg_match('/^\d{4}$/', $raw) === 1, 404);

        $year = (int) $raw;

        abort_if($year < 1900 || $year > 2100, 404);

        return view('diary.stats', ['stats' => $stats->forYear($year)]);
    }
}
