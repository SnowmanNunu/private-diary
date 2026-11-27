<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $raw = trim($request->string('month')->toString());

        if ($raw !== '' && ! preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $raw)) {
            abort(404);
        }

        $month = $raw === ''
            ? now()->startOfMonth()
            : Carbon::createFromFormat('!Y-m', $raw);

        $start = $month->copy()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $today = now()->toDateString();

        $entries = Entry::where('user_id', Auth::id())
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('entry_date')
            ->get()
            ->keyBy(fn (Entry $entry) => $entry->entry_date->format('Y-m-d'));

        $days = [];
        $cursor = $start->copy();

        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $days[] = [
                'date' => $date,
                'day' => $cursor->day,
                'inMonth' => $cursor->month === $month->month,
                'isToday' => $date === $today,
                'entry' => $entries->get($date),
            ];
            $cursor->addDay();
        }

        return view('diary.calendar', [
            'month' => $month,
            'weeks' => collect($days)->chunk(7),
            'monthCount' => $entries->filter(
                fn (Entry $entry, string $date) => str_starts_with($date, $month->format('Y-m')),
            )->count(),
            'prevMonth' => $month->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $month->copy()->addMonth()->format('Y-m'),
        ]);
    }
}
