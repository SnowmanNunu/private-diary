<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use App\Services\DiaryImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportController extends Controller
{
    public function index()
    {
        return view('diary.import', [
            'entryCount' => Entry::where('user_id', Auth::id())->count(),
            'preview' => session('import_preview'),
            'token' => session('import_token'),
        ]);
    }

    public function preview(Request $request, DiaryImporter $importer)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:json,zip'],
        ]);

        $file = $data['file'];
        $extension = strtolower($file->getClientOriginalExtension());

        abort_unless(in_array($extension, ['json', 'zip'], true), 422, '只支持 JSON 或 ZIP 文件');

        $directory = 'imports/'.Auth::id();
        $this->cleanup($directory);

        $token = Str::random(40);
        $filename = $token.'.'.$extension;
        $file->storeAs($directory, $filename, 'local');

        try {
            $summary = $importer->inspect(
                Storage::disk('local')->path($directory.'/'.$filename),
                $file->getClientOriginalName(),
            );
        } catch (Throwable $e) {
            Storage::disk('local')->delete($directory.'/'.$filename);

            return redirect()->route('diary.import')->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route('diary.import')
            ->with('import_preview', $summary)
            ->with('import_token', $token);
    }

    public function store(Request $request, DiaryImporter $importer)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:40'],
            'strategy' => ['required', 'in:skip,overwrite'],
        ]);

        $directory = 'imports/'.Auth::id();
        $path = collect(Storage::disk('local')->files($directory))
            ->first(fn (string $file): bool => str_starts_with(basename($file), $data['token'].'.'));

        abort_if($path === null, 404, '导入会话已失效，请重新上传');

        try {
            $result = $importer->import(
                Storage::disk('local')->path($path),
                basename($path),
                $data['strategy'],
            );
        } catch (Throwable $e) {
            return redirect()->route('diary.import')->withErrors(['file' => $e->getMessage()]);
        }

        Storage::disk('local')->delete($path);

        $message = sprintf(
            '导入完成：新增 %d 篇，更新 %d 篇，跳过 %d 篇，恢复图片 %d 张。',
            $result['imported'],
            $result['updated'],
            $result['skipped'],
            $result['attachments'],
        );

        return redirect()->route('diary.index')->with('status', $message);
    }

    private function cleanup(string $directory): void
    {
        foreach (Storage::disk('local')->files($directory) as $file) {
            $timestamp = Storage::disk('local')->lastModified($file);

            if (now()->getTimestamp() - $timestamp > 3600) {
                Storage::disk('local')->delete($file);
            }
        }
    }
}
