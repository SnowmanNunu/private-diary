<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use App\Services\DiaryExporter;
use Illuminate\Support\Facades\Auth;

class ExportController extends Controller
{
    public function index()
    {
        return view('diary.export', [
            'entryCount' => Entry::where('user_id', Auth::id())->count(),
        ]);
    }

    public function json(DiaryExporter $exporter)
    {
        return response()->streamDownload(
            fn () => print $exporter->json(),
            'private-diary-'.now()->format('Ymd-His').'.json',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public function markdown(DiaryExporter $exporter)
    {
        $path = tempnam(sys_get_temp_dir(), 'diary-');

        if ($path === false) {
            abort(500, '无法创建临时导出文件');
        }

        $exporter->writeMarkdownZip($path);

        return response()->download(
            $path,
            'private-diary-'.now()->format('Ymd-His').'.zip',
            ['Content-Type' => 'application/zip'],
        )->deleteFileAfterSend(true);
    }
}
