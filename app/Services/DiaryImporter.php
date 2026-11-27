<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\Tag;
use App\Services\AttachmentTypes;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class DiaryImporter
{
    private const MAX_ENTRIES = 2000;

    private const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    private const MAX_TOTAL_BYTES = 100 * 1024 * 1024;

    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain', 'text/csv', 'application/zip',
    ];

    public function inspect(string $path, string $filename): array
    {
        [$payload, $binaries, $warnings] = $this->readSource($path, $filename);
        $entries = $this->validateEntries($payload);
        $existing = $this->existingDates();

        $prepared = [];
        $new = 0;
        $conflict = 0;

        foreach ($entries as $entry) {
            $isConflict = isset($existing[$entry['date']]);
            $isConflict ? $conflict++ : $new++;
            $prepared[] = [
                'date' => $entry['date'],
                'title' => $entry['title'],
                'action' => $isConflict ? 'conflict' : 'new',
            ];
        }

        $attachmentCount = array_sum(array_map(
            fn (array $entry): int => count($entry['attachments']),
            $entries,
        ));

        if ($binaries === [] && $attachmentCount > 0) {
            $warnings[] = 'JSON 文件不包含图片二进制，附件元数据会跳过，正文中的图片链接保留原样。';
        }

        return [
            'source' => str_ends_with(strtolower($filename), '.zip') ? 'zip' : 'json',
            'total' => count($entries),
            'new' => $new,
            'conflict' => $conflict,
            'attachments' => $attachmentCount,
            'images' => count($binaries),
            'entries' => array_slice($prepared, 0, 20),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function import(string $path, string $filename, string $strategy): array
    {
        abort_unless(in_array($strategy, ['skip', 'overwrite'], true), 422);

        [$payload, $binaries, $warnings] = $this->readSource($path, $filename);
        $entries = $this->validateEntries($payload);
        $storedPaths = [];

        $result = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'attachments' => 0,
            'warnings' => $warnings,
        ];

        try {
            DB::transaction(function () use ($entries, $binaries, $strategy, &$result, &$storedPaths): void {
                foreach ($entries as $item) {
                    $entry = Entry::where('user_id', Auth::id())
                        ->where('entry_date', $item['date'])
                        ->first();

                    if ($entry !== null && $strategy === 'skip') {
                        $result['skipped']++;
                        continue;
                    }

                    $isNew = $entry === null;

                    if ($isNew) {
                        $entry = new Entry([
                            'user_id' => Auth::id(),
                            'entry_date' => $item['date'],
                        ]);
                    } elseif ($binaries !== []) {
                        $this->deleteAttachments($entry);
                    }

                    $entry->title = $item['title'];
                    $entry->body = $item['body'];
                    $entry->status = $item['status'];
                    $entry->mood = $item['mood'];
                    $entry->weather = $item['weather'];
                    $entry->save();

                    $this->syncTags($entry, $item['tags']);

                    $idMap = [];

                    foreach ($item['attachments'] as $attachment) {
                        $oldId = (int) $attachment['id'];
                        $binary = $binaries[$oldId] ?? null;

                        if ($binary === null) {
                            continue;
                        }

                        $fileName = $this->attachmentFileName($oldId, (string) $attachment['original_name']);
                        $path = 'attachments/'.Auth::id().'/imported/'.$entry->entry_date->format('Y/m').'/'.$fileName;

                        Storage::disk('local')->put($path, $binary['content']);
                        $storedPaths[] = $path;

                        $created = $entry->attachments()->create([
                            'user_id' => Auth::id(),
                            'path' => $path,
                            'original_name' => (string) $attachment['original_name'],
                            'mime' => $binary['mime'],
                            'size' => strlen($binary['content']),
                            'kind' => ($attachment['kind'] ?? null) === 'file'
                                ? 'file'
                                : AttachmentTypes::kindFor($binary['mime']),
                        ]);

                        $idMap[$oldId] = $created->id;
                        $result['attachments']++;
                    }

                    if ($idMap !== []) {
                        $entry->body = $this->rewriteAttachmentIds((string) $entry->body, $idMap);
                        $entry->save();
                    }

                    $isNew ? $result['imported']++ : $result['updated']++;
                }
            });
        } catch (Throwable $e) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }

        return $result;
    }

    private function readSource(string $path, string $filename): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('导入文件不存在');
        }

        if (filesize($path) > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('导入文件不能超过 100 MB');
        }

        if (str_ends_with(strtolower($filename), '.zip')) {
            return $this->readZip($path);
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException('无法读取导入文件');
        }

        return [$this->decodeManifest($raw), [], []];
    }

    private function readZip(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('无法打开 ZIP 文件');
        }

        try {
            $raw = $zip->getFromName('manifest.json');

            if ($raw === false) {
                throw new RuntimeException('ZIP 中缺少 manifest.json');
            }

            $manifest = $this->decodeManifest($raw);

            if ($zip->numFiles > 3000) {
                throw new RuntimeException('ZIP 内文件数量过多');
            }

            $binaries = [];
            $total = 0;
            $warnings = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');

                if (str_ends_with($name, '/')) {
                    continue;
                }

                if (! $this->isSafeZipEntry($name)) {
                    throw new RuntimeException('ZIP 中存在不安全的路径：'.$name);
                }

                if (! str_starts_with($name, 'attachments/')) {
                    continue;
                }

                $size = (int) ($stat['size'] ?? 0);

                if ($size > self::MAX_ATTACHMENT_BYTES) {
                    $warnings[] = basename($name).' 超过单个附件 20 MB，已跳过。';
                    continue;
                }

                $total += $size;

                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('ZIP 解压总大小超过 100 MB');
                }

                $content = $zip->getFromIndex($i);

                if ($content === false) {
                    throw new RuntimeException('无法读取 ZIP 中的附件');
                }

                $mime = $this->detectMime($content);

                if (! in_array($mime, self::ALLOWED_MIMES, true)) {
                    $warnings[] = basename($name).' 不是允许的附件格式，已跳过。';
                    continue;
                }

                $id = $this->attachmentIdFromName($name);

                if ($id === null) {
                    $warnings[] = basename($name).' 无法匹配附件编号，已跳过。';
                    continue;
                }

                $binaries[$id] = ['content' => $content, 'mime' => $mime];
            }

            return [$manifest, $binaries, $warnings];
        } finally {
            $zip->close();
        }
    }

    private function decodeManifest(string $raw): array
    {
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('导入文件不是有效的 JSON');
        }

        if (! is_array($payload) || ! in_array($payload['format'] ?? null, ['private-diary/1', 'private-diary/2'], true)) {
            throw new RuntimeException('不支持的导入格式，需要 private-diary/1 或 private-diary/2');
        }

        if (! isset($payload['entries']) || ! is_array($payload['entries'])) {
            throw new RuntimeException('导入文件缺少 entries 数据');
        }

        if (count($payload['entries']) > self::MAX_ENTRIES) {
            throw new RuntimeException('导入日记数量超过 2000 篇');
        }

        return $payload;
    }

    private function validateEntries(array $payload): array
    {
        $result = [];
        $seen = [];

        foreach ($payload['entries'] as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('日记条目格式错误');
            }

            $date = $this->validDate($item['date'] ?? null);
            $title = $item['title'] ?? null;
            $body = $item['body'] ?? null;
            $status = $item['status'] ?? 'draft';
            $mood = $item['mood'] ?? null;
            $weather = $item['weather'] ?? null;

            $mood = is_string($mood) && $mood !== '' && mb_strlen($mood) <= 20 ? $mood : null;
            $weather = is_string($weather) && $weather !== '' && mb_strlen($weather) <= 20 ? $weather : null;

            if ($title !== null && (! is_string($title) || mb_strlen($title) > 200)) {
                throw new RuntimeException($date.' 的标题不合法');
            }

            if ($body !== null && (! is_string($body) || mb_strlen($body) > 100000)) {
                throw new RuntimeException($date.' 的正文过长');
            }

            if (! in_array($status, ['draft', 'published'], true)) {
                throw new RuntimeException($date.' 的状态不合法');
            }

            $tags = [];

            foreach ((array) ($item['tags'] ?? []) as $tag) {
                if (! is_string($tag)) {
                    throw new RuntimeException($date.' 的标签格式错误');
                }

                $tag = trim($tag);

                if ($tag !== '' && mb_strlen($tag) <= 30 && count($tags) < 12 && ! in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }

            $attachments = [];

            foreach ((array) ($item['attachments'] ?? []) as $attachment) {
                if (! is_array($attachment) || ! isset($attachment['id'])) {
                    throw new RuntimeException($date.' 的附件格式错误');
                }

                $attachments[] = [
                    'id' => (int) $attachment['id'],
                    'original_name' => (string) ($attachment['original_name'] ?? 'attachment'),
                ];
            }

            if (isset($seen[$date])) {
                throw new RuntimeException('导入文件中存在重复日期：'.$date);
            }

            $seen[$date] = true;
            $result[] = [
                'date' => $date,
                'title' => $title,
                'body' => $body,
                'status' => $status,
                'mood' => $mood,
                'weather' => $weather,
                'tags' => $tags,
                'attachments' => $attachments,
            ];
        }

        return $result;
    }

    private function validDate(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('日记日期格式错误');
        }

        $parsed = Carbon::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new RuntimeException('无效日期：'.$value);
        }

        return $value;
    }

    private function existingDates(): array
    {
        return Entry::where('user_id', Auth::id())
            ->pluck('entry_date')
            ->mapWithKeys(fn ($date): array => [$date->format('Y-m-d') => true])
            ->all();
    }

    private function syncTags(Entry $entry, array $names): void
    {
        $ids = [];

        foreach ($names as $name) {
            $tag = Tag::firstOrCreate([
                'user_id' => Auth::id(),
                'name' => $name,
            ]);

            $ids[$tag->id] = $tag->id;
        }

        $entry->tags()->sync(array_values($ids));
    }

    private function deleteAttachments(Entry $entry): void
    {
        foreach ($entry->attachments as $attachment) {
            Storage::disk('local')->delete($attachment->path);
            $attachment->delete();
        }
    }

    private function rewriteAttachmentIds(string $body, array $idMap): string
    {
        return preg_replace_callback('#/attachments/(\d+)#', function (array $m) use ($idMap): string {
            return '/attachments/'.($idMap[(int) $m[1]] ?? $m[1]);
        }, $body) ?? $body;
    }

    private function attachmentFileName(int $id, string $name): string
    {
        $safeName = pathinfo($name, PATHINFO_BASENAME);
        $safeName = trim(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $safeName) ?? '', '-._');

        return $id.'-'.($safeName === '' ? 'attachment' : $safeName);
    }

    private function isSafeZipEntry(string $name): bool
    {
        if ($name === '' || str_contains($name, '\\') || str_starts_with($name, '/')) {
            return false;
        }

        $parts = explode('/', $name);

        if (in_array('..', $parts, true) || in_array('.', $parts, true)) {
            return false;
        }

        if ($parts[0] === 'manifest.json') {
            return count($parts) === 1;
        }

        if ($parts[0] === 'entries') {
            return count($parts) === 3 && str_ends_with($parts[2], '.md');
        }

        return $parts[0] === 'attachments' && count($parts) === 2;
    }

    private function attachmentIdFromName(string $name): ?int
    {
        $base = basename($name);

        return preg_match('/^(\d+)-/', $base, $matches) === 1 ? (int) $matches[1] : null;
    }

    private function detectMime(string $content): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return '';
        }

        try {
            return (string) finfo_buffer($finfo, $content);
        } finally {
            finfo_close($finfo);
        }
    }
}
