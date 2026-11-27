<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Entry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class DiaryExporter
{
    public function json(): string
    {
        $payload = [
            'format' => 'private-diary/2',
            'exported_at' => now()->toIso8601String(),
            'entries' => $this->entries()->map(fn (Entry $entry) => [
                'date' => $entry->entry_date->format('Y-m-d'),
                'title' => $entry->title,
                'body' => $entry->body,
                'status' => $entry->status,
                'mood' => $entry->mood,
                'weather' => $entry->weather,
                'tags' => $entry->tags->pluck('name')->all(),
                'attachments' => $entry->attachments->map(fn (Attachment $attachment) => [
                    'id' => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'mime' => $attachment->mime,
                    'size' => $attachment->size,
                ])->all(),
            ])->all(),
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
    }

    public function writeMarkdownZip(string $path): void
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('无法创建导出压缩包');
        }

        foreach ($this->entries() as $entry) {
            $replacements = [];

            foreach ($entry->attachments as $attachment) {
                $fileName = $this->attachmentFileName($attachment);
                $replacements[(string) $attachment->id] = '../attachments/'.$fileName;

                if (Storage::disk('local')->exists($attachment->path)) {
                    $zip->addFromString(
                        'attachments/'.$fileName,
                        (string) Storage::disk('local')->get($attachment->path),
                    );
                }
            }

            $markdown = implode("\n", [
                '# '.($entry->title ?: '无标题'),
                '',
                '- 日期：'.$entry->entry_date->format('Y-m-d'),
                '- 状态：'.($entry->status === 'draft' ? '草稿' : '已发布'),
                '- 心情：'.($entry->mood ? (config('diary.moods.'.$entry->mood) ?: $entry->mood) : '无'),
                '- 天气：'.($entry->weather ? (config('diary.weather.'.$entry->weather) ?: $entry->weather) : '无'),
                '- 标签：'.($entry->tags->isEmpty() ? '无' : $entry->tags->pluck('name')->implode(', ')),
                '',
                $this->rewriteAttachmentLinks($entry->body ?? '', $replacements),
                '',
            ]);

            $zip->addFromString(
                'entries/'.$entry->entry_date->format('Y').'/'.$entry->entry_date->format('Y-m-d').'.md',
                $markdown,
            );
        }

        $zip->addFromString('manifest.json', $this->json());
        $zip->close();
    }

    private function entries()
    {
        return Entry::where('user_id', Auth::id())
            ->with(['tags', 'attachments'])
            ->orderBy('entry_date')
            ->get();
    }

    private function rewriteAttachmentLinks(string $body, array $replacements): string
    {
        if ($replacements === []) {
            return $body;
        }

        return preg_replace_callback('#/attachments/(\d+)#', function (array $matches) use ($replacements): string {
            return $replacements[$matches[1]] ?? $matches[0];
        }, $body) ?? $body;
    }

    private function attachmentFileName(Attachment $attachment): string
    {
        $name = pathinfo($attachment->original_name ?: 'attachment', PATHINFO_BASENAME);
        $safe = trim(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $name) ?? '', '-._');

        return $attachment->id.'-'.($safe === '' ? 'attachment' : $safe);
    }
}
