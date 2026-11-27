<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use App\Models\EntryConflict;
use App\Models\Tag;
use App\Services\BackupHealth;
use App\Services\DiaryIcons;
use App\Services\DiaryStats;
use App\Services\EntrySearch;
use App\Services\Highlight;
use App\Services\LastYearReview;
use App\Services\Markdown;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DiaryController extends Controller
{
    public function __construct(
        private Markdown $markdown,
        private EntrySearch $search,
        private LastYearReview $review,
        private Highlight $highlight,
        private DiaryIcons $icons,
    ) {
    }

    public function index(Request $request)
    {
        $query = Entry::where('user_id', Auth::id())
            ->with(['tags', 'attachments'])
            ->orderByDesc('entry_date');

        $filters = $request->validate([
            'mood' => ['nullable', Rule::in(array_keys(config('diary.moods')))],
            'weather' => ['nullable', Rule::in(array_keys(config('diary.weather')))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $keyword = trim($request->string('q')->toString());
        $tag = trim($request->string('tag')->toString());
        $mood = $filters['mood'] ?? '';
        $weather = $filters['weather'] ?? '';
        $from = $filters['from'] ?? '';
        $to = $filters['to'] ?? '';

        if ($keyword !== '') {
            $query->whereIn('id', $this->search->ids($keyword));
        }

        if ($tag !== '') {
            $query->whereHas('tags', fn ($builder) => $builder->where('name', $tag));
        }

        if ($mood !== '') {
            $query->where('mood', $mood);
        }

        if ($weather !== '') {
            $query->where('weather', $weather);
        }

        if ($from !== '') {
            $query->whereDate('entry_date', '>=', $from);
        }

        if ($to !== '') {
            $query->whereDate('entry_date', '<=', $to);
        }

        return view('diary.index', [
            'entries' => $query->paginate(20)->withQueryString(),
            'keyword' => $keyword,
            'activeTag' => $tag,
            'mood' => $mood,
            'weather' => $weather,
            'from' => $from,
            'to' => $to,
            'moods' => config('diary.moods'),
            'weathers' => config('diary.weather'),
            'highlight' => $this->highlight,
            'icons' => $this->icons,
            'lastYearEntry' => $this->review->today(),
            'health' => app(BackupHealth::class)->status(),
            'streak' => app(DiaryStats::class)->streak(),
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
            'moods' => config('diary.moods'),
            'weathers' => config('diary.weather'),
            'icons' => $this->icons,
            'conflict' => session('conflict'),
            'conflicts' => EntryConflict::where('user_id', Auth::id())
                ->where('entry_date', $date)
                ->latest()
                ->get(),
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
            'icons' => $this->icons,
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
            'mood' => ['nullable', 'string', 'max:20', Rule::in(array_keys(config('diary.moods')))],
            'weather' => ['nullable', 'string', 'max:20', Rule::in(array_keys(config('diary.weather')))],
            'revision' => ['nullable', 'integer', 'min:0'],
            'force' => ['nullable', 'boolean'],
        ]);

        $entry = Entry::firstOrNew([
            'user_id' => Auth::id(),
            'entry_date' => $date,
        ]);

        $clientRevision = isset($data['revision']) ? (int) $data['revision'] : null;
        $force = $request->boolean('force');

        // 多设备 / 离线草稿同时写同一天时，绝不静默覆盖：先留副本再拒绝。
        if ($entry->exists
            && ! $force
            && $clientRevision !== null
            && $clientRevision !== (int) $entry->revision) {
            return $this->conflictResponse($request, $entry, $data, $clientRevision);
        }

        $this->applyFields($entry, $data, $request->boolean('autosave'));
        $entry->revision = $entry->exists ? (int) $entry->revision + 1 : 1;
        $entry->save();

        if ($request->has('tags')) {
            $this->syncTags($entry, $data['tags'] ?? null);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => $entry->id,
                'status' => $entry->status,
                'revision' => $entry->revision,
                'saved_at' => now()->format('H:i:s'),
            ]);
        }

        return redirect()->route('diary.show', $date)->with('status', '已保存');
    }

    /** @param array<string, mixed> $data */
    private function conflictResponse(Request $request, Entry $entry, array $data, int $clientRevision)
    {
        $copy = EntryConflict::create([
            'user_id' => Auth::id(),
            'entry_id' => $entry->id,
            'entry_date' => $entry->entry_date,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'tags' => $data['tags'] ?? null,
            'mood' => $data['mood'] ?? null,
            'weather' => $data['weather'] ?? null,
            'source' => $request->boolean('autosave') ? 'autosave' : 'form',
            'client_revision' => $clientRevision,
            'server_revision' => (int) $entry->revision,
        ]);

        $server = [
            'title' => $entry->title,
            'body' => $entry->body,
            'tags' => $entry->tags->pluck('name')->implode(', '),
            'mood' => $entry->mood,
            'weather' => $entry->weather,
            'revision' => (int) $entry->revision,
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'conflict' => true,
                'message' => '检测到其他设备已修改这一天的日记。',
                'server_revision' => (int) $entry->revision,
                'server' => $server,
                'conflict_id' => $copy->id,
            ], 409);
        }

        return back()->withInput()
            ->withErrors(['conflict' => '检测到其他设备已修改这一天的日记，你刚写的内容已保存为冲突副本，不会丢失。'])
            ->with('conflict', [
                'id' => $copy->id,
                'server' => $server,
            ]);
    }

    /** @param array<string, mixed> $data */
    private function applyFields(Entry $entry, array $data, bool $autosave): void
    {
        $entry->title = $data['title'] ?? null;
        $entry->body = $data['body'] ?? null;
        $entry->mood = $data['mood'] ?? null;
        $entry->weather = $data['weather'] ?? null;

        if (! $autosave) {
            $entry->status = 'published';
        } elseif (! $entry->exists) {
            $entry->status = 'draft';
        }
    }

    public function restoreConflict(Request $request, string $date, EntryConflict $conflict)
    {
        $date = $this->validDate($date);
        abort_unless($conflict->user_id === Auth::id(), 404);
        abort_unless($conflict->entry_date->toDateString() === $date, 404);

        $entry = Entry::firstOrNew([
            'user_id' => Auth::id(),
            'entry_date' => $date,
        ]);

        $entry->title = $conflict->title;
        $entry->body = $conflict->body;
        $entry->mood = $conflict->mood;
        $entry->weather = $conflict->weather;
        $entry->revision = $entry->exists ? (int) $entry->revision + 1 : 1;
        $entry->save();

        $this->syncTags($entry, $conflict->tags);
        $conflict->delete();

        return redirect()->route('diary.create', ['date' => $date])->with('status', '冲突副本已恢复为当前内容。');
    }

    public function destroyConflict(Request $request, string $date, EntryConflict $conflict)
    {
        $this->validDate($date);
        abort_unless($conflict->user_id === Auth::id(), 404);
        abort_unless($conflict->entry_date->toDateString() === $date, 404);

        $conflict->delete();

        return redirect()->route('diary.create', ['date' => $date])->with('status', '冲突副本已删除。');
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
