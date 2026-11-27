<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entries', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
        });

        Schema::table('backup_audits', function (Blueprint $table): void {
            $table->string('kind', 16)->default('verify');
        });

        Schema::create('entry_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->nullable()->constrained('entries')->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('title')->nullable();
            $table->longText('body')->nullable();
            $table->string('tags')->nullable();
            $table->string('mood')->nullable();
            $table->string('weather')->nullable();
            $table->string('source', 32)->default('autosave');
            $table->unsignedInteger('client_revision')->nullable();
            $table->unsignedInteger('server_revision')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_conflicts');
        Schema::table('backup_audits', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('entries', fn (Blueprint $table) => $table->dropColumn('revision'));
    }
};
