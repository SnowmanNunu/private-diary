<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('backup_path')->nullable();
            $table->string('status', 16);
            $table->json('details')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_audits');
    }
};
