<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Entry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function store(Request $request, string $date)
    {
        $date = $this->validDate($date);

        $data = $request->validate([
            'images' => ['required', 'array', 'max:6'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:8192'],
        ]);

        $entry = Entry::firstOrNew([
            'user_id' => Auth::id(),
            'entry_date' => $date,
        ]);

        if (! $entry->exists) {
            $entry->status = 'draft';
            $entry->save();
        }

        $attachments = [];

        foreach ($data['images'] as $file) {
            $path = $file->store(
                'attachments/'.Auth::id().'/'.now()->format('Y/m'),
                'local',
            );

            $attachment = Attachment::create([
                'user_id' => Auth::id(),
                'entry_id' => $entry->id,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);

            $attachments[] = [
                'id' => $attachment->id,
                'url' => route('attachments.show', $attachment),
                'markdown' => '!['.$attachment->original_name.']('.route('attachments.show', $attachment).')',
            ];
        }

        return response()->json(['ok' => true, 'attachments' => $attachments], 201);
    }

    public function show(Attachment $attachment)
    {
        abort_if($attachment->user_id !== Auth::id(), 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->response(
            $attachment->path,
            $attachment->original_name,
            ['Cache-Control' => 'private, no-store'],
            'inline',
        );
    }

    public function destroy(Attachment $attachment)
    {
        abort_if($attachment->user_id !== Auth::id(), 404);

        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        return response()->json(['ok' => true]);
    }

    private function validDate(string $date): string
    {
        $parsed = Carbon::createFromFormat('!Y-m-d', $date);

        abort_if($parsed === false || $parsed->format('Y-m-d') !== $date, 404);

        return $date;
    }
}
