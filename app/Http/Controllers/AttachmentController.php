<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Entry;
use App\Services\AttachmentTypes;
use App\Services\DocumentPreview;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function store(Request $request, string $date)
    {
        $date = $this->validDate($date);

        $data = $request->validate([
            'images' => ['nullable', 'array', 'max:'.AttachmentTypes::MAX_PER_UPLOAD],
            'images.*' => [
                'required', 'image', 'mimes:'.AttachmentTypes::IMAGE_EXTENSIONS,
                'mimetypes:'.implode(',', AttachmentTypes::IMAGE_MIMES),
                'max:'.AttachmentTypes::MAX_IMAGE_KB,
                $this->mimeConsistencyRule(),
            ],
            'documents' => ['nullable', 'array', 'max:'.AttachmentTypes::MAX_PER_UPLOAD],
            'documents.*' => [
                'required', 'file', 'mimes:'.AttachmentTypes::DOCUMENT_EXTENSIONS,
                'mimetypes:'.implode(',', array_keys(AttachmentTypes::DOCUMENT_MIMES)),
                'max:'.AttachmentTypes::MAX_DOCUMENT_KB,
                $this->mimeConsistencyRule(),
            ],
        ]);

        $images = $data['images'] ?? [];
        $documents = $data['documents'] ?? [];

        if ($images === [] && $documents === []) {
            return response()->json(['message' => '请选择要上传的文件。'], 422);
        }

        if (count($images) + count($documents) > AttachmentTypes::MAX_PER_UPLOAD) {
            return response()->json(['message' => '一次最多上传 '.AttachmentTypes::MAX_PER_UPLOAD.' 个文件。'], 422);
        }

        $entry = Entry::firstOrNew([
            'user_id' => Auth::id(),
            'entry_date' => $date,
        ]);

        if (! $entry->exists) {
            $entry->status = 'draft';
            $entry->save();
        }

        $attachments = [];

        foreach ([['files' => $images, 'kind' => 'image'], ['files' => $documents, 'kind' => 'file']] as $group) {
            foreach ($group['files'] as $file) {
                $path = $file->store(
                    'attachments/'.Auth::id().'/'.now()->format('Y/m'),
                    'local',
                );

                $mime = $file->getMimeType() ?: 'application/octet-stream';
                $kind = $group['kind'];

                $attachment = Attachment::create([
                    'user_id' => Auth::id(),
                    'entry_id' => $entry->id,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime' => $mime,
                    'size' => $file->getSize(),
                    'kind' => $kind,
                ]);

                $url = route('attachments.show', $attachment);
                $attachments[] = [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'kind' => $kind,
                    'badge' => $attachment->badge(),
                    'size' => (int) $attachment->size,
                    'url' => $url,
                    'preview_url' => route('attachments.preview', $attachment),
                    'markdown' => $kind === 'image'
                        ? '!['.$attachment->original_name.']('.$url.')'
                        : '['.$attachment->original_name.']('.$url.')',
                ];
            }
        }

        return response()->json(['ok' => true, 'attachments' => $attachments], 201);
    }

    public function show(Attachment $attachment)
    {
        abort_if($attachment->user_id !== Auth::id(), 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        // 图片与 PDF 可在浏览器内预览；Word / Excel / PPT 等一律强制下载，
        // 避免浏览器错误解析或执行文件内容。预览页只读取解析后的文本，不依赖内联渲染。
        $disposition = $attachment->isImage() || $attachment->mime === 'application/pdf'
            ? 'inline'
            : 'attachment';

        return Storage::disk('local')->response(
            $attachment->path,
            $attachment->original_name,
            ['Cache-Control' => 'private, no-store'],
            $disposition,
        );
    }

    public function preview(Attachment $attachment, DocumentPreview $preview)
    {
        abort_if($attachment->user_id !== Auth::id(), 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        $payload = $preview->preview($attachment);

        return response()->view('attachments.preview', [
            'attachment' => $attachment,
            'preview' => $payload,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function previewFile(Attachment $attachment, DocumentPreview $preview)
    {
        abort_if($attachment->user_id !== Auth::id(), 404);

        $path = $preview->previewPath($attachment);

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            'preview.pdf',
            ['Cache-Control' => 'private, no-store'],
            'inline',
        );
    }

    public function destroy(Attachment $attachment, DocumentPreview $preview)
    {
        abort_if($attachment->user_id !== Auth::id(), 404);

        Storage::disk('local')->delete($attachment->path);
        $preview->deletePreview($attachment);
        $attachment->delete();

        return response()->json(['ok' => true]);
    }

    private function mimeConsistencyRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            if (! AttachmentTypes::extensionMatchesMime(
                $value->getClientOriginalExtension(),
                (string) $value->getMimeType(),
            )) {
                $fail('文件扩展名与实际内容不一致。');
            }
        };
    }

    private function validDate(string $date): string
    {
        $parsed = Carbon::createFromFormat('!Y-m-d', $date);

        abort_if($parsed === false || $parsed->format('Y-m-d') !== $date, 404);

        return $date;
    }
}
