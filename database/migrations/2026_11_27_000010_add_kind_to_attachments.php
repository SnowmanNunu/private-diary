<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            // 历史附件全部是图片；文档附件写入 file。
            $table->string('kind', 8)->default('image')->after('mime');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
