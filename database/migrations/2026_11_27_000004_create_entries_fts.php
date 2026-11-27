<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIRTUAL TABLE IF NOT EXISTS entries_fts USING fts5(
                title,
                body,
                entry_id UNINDEXED,
                tokenize = 'trigram'
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS entries_fts_insert
            AFTER INSERT ON entries
            BEGIN
                INSERT INTO entries_fts(rowid, title, body, entry_id)
                VALUES (new.id, coalesce(new.title, ''), coalesce(new.body, ''), new.id);
            END
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS entries_fts_delete
            AFTER DELETE ON entries
            BEGIN
                DELETE FROM entries_fts WHERE entry_id = old.id;
            END
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS entries_fts_update
            AFTER UPDATE ON entries
            BEGIN
                DELETE FROM entries_fts WHERE entry_id = old.id;
                INSERT INTO entries_fts(rowid, title, body, entry_id)
                VALUES (new.id, coalesce(new.title, ''), coalesce(new.body, ''), new.id);
            END
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO entries_fts(rowid, title, body, entry_id)
            SELECT id, coalesce(title, ''), coalesce(body, ''), id FROM entries
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS entries_fts_insert');
        DB::statement('DROP TRIGGER IF EXISTS entries_fts_delete');
        DB::statement('DROP TRIGGER IF EXISTS entries_fts_update');
        DB::statement('DROP TABLE IF EXISTS entries_fts');
    }
};
