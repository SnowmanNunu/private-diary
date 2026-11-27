<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Entry;
use App\Models\User;
use App\Services\DiaryExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DiaryV07Test extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_and_word_documents_can_be_uploaded(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/diary/2026-11-27/attachments', [
            'documents' => [
                UploadedFile::fake()->create('合同.pdf', 4, 'application/pdf'),
                UploadedFile::fake()->create('周报.docx', 4, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ],
        ])->assertCreated()->assertJsonCount(2, 'attachments');

        $attachments = Attachment::orderBy('id')->get();
        $this->assertCount(2, $attachments);
        $this->assertTrue($attachments->every(fn (Attachment $a): bool => $a->kind === 'file'));
        $this->assertSame('PDF', $attachments[0]->badge());
        $this->assertSame('DOCX', $attachments[1]->badge());
        Storage::disk('local')->assertExists($attachments[0]->path);

        $this->assertStringStartsWith('[合同.pdf](', (string) $response->json('attachments.0.markdown'));
        $this->assertStringStartsWith('[周报.docx](', (string) $response->json('attachments.1.markdown'));
    }

    public function test_images_still_upload_as_images(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/diary/2026-11-27/attachments', [
            'images' => [UploadedFile::fake()->image('海边.jpg', 10, 10)],
        ])->assertCreated();

        $attachment = Attachment::firstOrFail();
        $this->assertSame('image', $attachment->kind);
        $this->assertTrue($attachment->isImage());
        $this->assertStringStartsWith('![海边.jpg](', $attachment->markdown('http://example.test/a'));
    }

    public function test_pdf_and_images_are_inline_but_office_documents_are_downloaded(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/diary/2026-11-27/attachments', [
            'documents' => [
                UploadedFile::fake()->create('合同.pdf', 4, 'application/pdf'),
                UploadedFile::fake()->create('周报.docx', 4, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ],
        ])->assertCreated();
        $this->actingAs($user)->post('/diary/2026-11-27/attachments', [
            'images' => [UploadedFile::fake()->image('海边.jpg', 10, 10)],
        ])->assertCreated();

        $pdf = Attachment::where('original_name', '合同.pdf')->firstOrFail();
        $docx = Attachment::where('original_name', '周报.docx')->firstOrFail();
        $image = Attachment::where('original_name', '海边.jpg')->firstOrFail();

        $pdfHeader = $this->actingAs($user)->get(route('attachments.show', $pdf))
            ->assertOk()->headers->get('Content-Disposition') ?? '';
        $imageHeader = $this->actingAs($user)->get(route('attachments.show', $image))
            ->assertOk()->headers->get('Content-Disposition') ?? '';
        $docxHeader = $this->actingAs($user)->get(route('attachments.show', $docx))
            ->assertOk()->headers->get('Content-Disposition') ?? '';

        $this->assertStringContainsString('inline', $pdfHeader);
        $this->assertStringContainsString('inline', $imageHeader);
        $this->assertStringContainsString('attachment;', $docxHeader);
        $this->assertStringContainsString('no-store', (string) $this->actingAs($user)
            ->get(route('attachments.show', $docx))->headers->get('Cache-Control'));
    }

    public function test_disguised_and_oversized_documents_are_rejected(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->withHeader('Accept', 'application/json')
            ->post('/diary/2026-11-27/attachments', [
                'documents' => [UploadedFile::fake()->create('木马.pdf', 4, 'text/plain')],
            ])->assertStatus(422)->assertJsonStructure(['message', 'errors']);

        $this->actingAs($user)->withHeader('Accept', 'application/json')
            ->post('/diary/2026-11-27/attachments', [
                'documents' => [UploadedFile::fake()->create('超大.pdf', 20481, 'application/pdf')],
            ])->assertStatus(422)->assertJsonStructure(['message', 'errors']);

        $this->assertSame(0, Attachment::count());
    }

    public function test_empty_upload_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/diary/2026-11-27/attachments', [])
            ->assertStatus(422)
            ->assertJson(['message' => '请选择要上传的文件。']);
    }

    public function test_document_is_private_to_its_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)->post('/diary/2026-11-27/attachments', [
            'documents' => [UploadedFile::fake()->create('私密.pdf', 4, 'application/pdf')],
        ])->assertCreated();

        $attachment = Attachment::firstOrFail();

        $this->actingAs($other)->get(route('attachments.show', $attachment))->assertNotFound();
        $this->actingAs($other)->delete(route('attachments.destroy', $attachment))->assertNotFound();

        $this->actingAs($owner)->delete(route('attachments.destroy', $attachment))->assertOk();
        Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_editor_and_reader_render_document_cards(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $entry = Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
            'title' => '搬家',
            'body' => '正文',
            'status' => 'published',
        ]);
        Storage::disk('local')->put('attachments/contract.pdf', '%PDF-1.4 test');

        $attachment = Attachment::create([
            'user_id' => $user->id,
            'entry_id' => $entry->id,
            'path' => 'attachments/contract.pdf',
            'original_name' => '租房合同.pdf',
            'mime' => 'application/pdf',
            'size' => 15,
            'kind' => 'file',
        ]);

        $this->actingAs($user)->get('/diary/create?date=2026-11-27')
            ->assertOk()
            ->assertSee('上传文档')
            ->assertSee('attachment-file', false)
            ->assertSee('租房合同.pdf')
            ->assertSee('PDF', false)
            ->assertSee('[租房合同.pdf]('.route('attachments.show', $attachment).')', false);

        $this->actingAs($user)->get('/diary/2026-11-27')
            ->assertOk()
            ->assertSee('附件')
            ->assertSee('attachment-file', false)
            ->assertSee('租房合同.pdf');
    }

    public function test_export_and_import_preserve_document_attachments(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $entry = Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
            'title' => '带附件的日记',
            'body' => '合同见 [租房合同.pdf](/attachments/1)',
            'status' => 'published',
        ]);
        Storage::disk('local')->put('attachments/contract.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        Attachment::create([
            'user_id' => $user->id,
            'entry_id' => $entry->id,
            'path' => 'attachments/contract.pdf',
            'original_name' => '租房合同.pdf',
            'mime' => 'application/pdf',
            'size' => 42,
            'kind' => 'file',
        ]);

        $json = $this->actingAs($user)->get('/export/json')->streamedContent();
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('private-diary/2', $payload['format']);
        $this->assertSame('file', $payload['entries'][0]['attachments'][0]['kind']);
        $this->assertSame('application/pdf', $payload['entries'][0]['attachments'][0]['mime']);

        $path = tempnam(sys_get_temp_dir(), 'diary-v07-');
        $this->assertNotFalse($path);
        $this->app->make(DiaryExporter::class)->writeMarkdownZip($path);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $this->assertStringContainsString(
            '[租房合同.pdf](../attachments/1-租房合同.pdf)',
            (string) $zip->getFromName('entries/2026/2026-11-27.md'),
        );
        $zip->close();

        // 清空原数据后从导出的 ZIP 恢复。
        Entry::query()->delete();
        $file = UploadedFile::fake()->createWithContent('backup.zip', (string) file_get_contents($path));
        $this->actingAs($user)->post('/import/preview', ['file' => $file])
            ->assertRedirect(route('diary.import'));

        $token = session('import_token');
        $this->assertIsString($token);
        $this->assertSame(1, session('import_preview.attachments'));

        $this->actingAs($user)->post('/import', [
            'token' => $token,
            'strategy' => 'overwrite',
        ])->assertRedirect(route('diary.index'));

        $restored = Attachment::firstOrFail();
        $this->assertSame('file', $restored->kind);
        $this->assertSame('租房合同.pdf', $restored->original_name);
        Storage::disk('local')->assertExists($restored->path);
        unlink($path);
    }
}
