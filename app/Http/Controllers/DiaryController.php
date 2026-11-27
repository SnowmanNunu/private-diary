<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use App\Models\Tag;
use App\Services\EntrySearch;
use App\Services\LastYearReview;
use App\Services\Markdown;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class DiaryController extends Controller
{
    public function __construct(
        private Markdown $markdown,
        private EntrySearch $search,
        private LastYearReview $review,
    ) {
    }

    public function index(Request $request)
    {
        $query = Entry::where('user_id', Auth::id())
            ->with(['tags', 'attachments'])
            ->orderByDesc('entry_date');

        $keyword = trim($request->string('q')->toString());
        $tag = trim($request->string('tag')->toString());

        if ($keyword !== '') {
            $query->whereIn('id', $this->search->ids($keyword));
        }

        if ($tag !== '') {
            $query->whereHas('tags', fn ($builder) => $builder->where('name', $tag));
        }

        return view('diary.index', [
            'entries' => $query->paginate(20)->withQueryString(),
            'keyword' => $keyword,
            'activeTag' => $tag,
            'lastYearEntry' => $this->review->today(),
            'tags' => Tag::where('user_id', Auth::id())
                ->withCount('entries')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(Request $request)
    {
        $date = $this->validDate($request->string('date')->toString() ?: now()->toDateString());
        $entry = $this->findEntry($date);
        $entry?->load(['tags', 'attachments']);

        return view('diary.edit', [
            'date' => $date,
            'entry' => $entry,
        ]);
    }

    public function show(string $date)
    {
        $date = $this->validDate($date);
        $entry = $this->findEntry($date);

        abort_if($entry === null, 404);

        $entry->load(['tags', 'attachments']);

        return view('diary.show', [
            'entry' => $entry,
            'html' => $this->markdown->render($entry->body),
            'lastYearEntry' => $this->review->for($date),
        ]);
    }

    public function store(Request $request, string $date)
    {
        $date = $this->validDate($date);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:100000'],
            'tags' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = Entry::firstOrNew([
            'user_id' => Auth::id(),
            'entry_date' => $date,
        ]);

        $entry->title = $data['title'] ?? null;
        $entry->body = $data['body'] ?? null;

        if (! $request->boolean('autosave')) {
            $entry->status = 'published';
        } elseif (! $entry->exists) {
            $entry->status = 'draft';
        }

        $entry->save();

        if ($request->has('tags')) {
            $this->syncTags($entry, $data['tags'] ?? null);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => $entry->id,
                'status' => $entry->status,
                'saved_at' => now()->format('H:i:s'),
            ]);
        }

        return redirect()->route('diary.show', $date)->with('status', '已保存');
    }

    public function destroy(string $date)
    {
        $entry = $this->findEntry($this->validDate($date));

        abort_if($entry === null, 404);

        foreach ($entry->attachments as $attachment) {
            Storage::disk('local')->delete($attachment->path);
        }

        $entry->delete();

        return redirect()->route('diary.index')->with('status', '已删除');
    }

    public function preview(Request $request)
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:100000'],
        ]);

        return response()->json([
            'html' => $this->markdown->render($data['body'] ?? null),
        ]);
    }

    private function syncTags(Entry $entry, ?string $raw): void
    {
        $names = preg_split('/[,，\n]+/u', $raw ?? '') ?: [];
        $tagIds = [];

        foreach ($names as $name) {
            $name = trim($name);

            if ($name === '' || mb_strlen($name) > 30 || count($tagIds) >= 12) {
                continue;
            }

            $tag = Tag::firstOrCreate([
                'user_id' => Auth::id(),
                'name' => $name,
            ]);

            $tagIds[$tag->id] = $tag->id;
        }

        $entry->tags()->sync(array_values($tagIds));
    }

    private function findEntry(string $date): ?Entry
    {
        return Entry::where('user_id', Auth::id())
            ->where('entry_date', $date)
            ->first();
    }

    private function validDate(string $date): string
    {
        $parsed = Carbon::createFromFormat('!Y-m-d', $date);

        abort_if($parsed === false || $parsed->format('Y-m-d') !== $date, 404);

        return $date;
    }
}
