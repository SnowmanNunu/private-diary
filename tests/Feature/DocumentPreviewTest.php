<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Entry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class DocumentPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_file_is_previewed_with_escaped_content(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $attachment = $this->makeAttachment($user, 'notes.txt', 'text/plain', "<script>alert(1)</script>\n第二行");

        $response = $this->actingAs($user)->get(route('attachments.preview', $attachment))
            ->assertOk()
            ->assertSee('附件预览')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('第二行')
            ->assertDontSee('<script>alert(1)</script>', false);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_gbk_text_is_converted_to_utf8(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $gbk = mb_convert_encoding('中文内容', 'GB18030', 'UTF-8');
        $attachment = $this->makeAttachment($user, 'notes.txt', 'text/plain', $gbk);

        $this->actingAs($user)->get(route('attachments.preview', $attachment))
            ->assertOk()
            ->assertSee('中文内容');
    }

    public function test_csv_is_rendered_as_a_table(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $attachment = $this->makeAttachment($user, '账单.csv', 'text/csv', "日期,金额\n2026-11-01,120\n2026-11-02,80");

        $this->actingAs($user)->get(route('attachments.preview', $attachment))
            ->assertOk()
            ->assertSee('preview-table', false)
            ->assertSee('2026-11-01')
            ->assertSee('120');
    }

    public function test_pdf_and_images_use_native_iframe_and_image_tags(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $pdf = $this->makeAttachment($user, '合同.pdf', 'application/pdf', '%PDF-1.4 test');
        $image = $this->makeAttachment($user, '海边.jpg', 'image/jpeg', 'binary', 'image');

        $this->actingAs($user)->get(route('attachments.preview', $pdf))
            ->assertOk()
            ->assertSee('preview-frame', false)
            ->assertSee(route('attachments.show', $pdf), false);

        $this->actingAs($user)->get(route('attachments.preview', $image))
            ->assertOk()
            ->assertSee('preview-image', false);
    }

    public function test_docx_paragraphs_are_extracted_locally(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'diary-docx-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            .'<w:p><w:r><w:t>第一段文字</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>第二段</w:t></w:r><w:r><w:t> continued</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->close();

        $attachment = $this->makeAttachment($user, '周报.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', (string) file_get_contents($path));
        unlink($path);

        $this->actingAs($user)->get(route('attachments.preview', $attachment))
            ->assertOk()
            ->assertSee('第一段文字')
            ->assertSee('第二段 continued');
    }

    public function test_xlsx_cells_are_extracted_as_a_table(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'diary-xlsx-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<si><t>姓名</t></si><si><t>年龄</t></si><si><t>张三</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>30</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $attachment = $this->makeAttachment($user, '名单.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', (string) file_get_contents($path));
        unlink($path);

        $this->actingAs($user)->get(route('attachments.preview', $attachment))
            ->assertOk()
            ->assertSee('preview-table', false)
            ->assertSee('姓名')
            ->assertSee('张三')
            ->assertSee('30');
    }

    public function test_pptx_slide_text_is_extracted(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'diary-pptx-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $slide = '<?xml version="1.0"?>'
            .'<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<a:p><a:r><a:t>季度总结</a:t></a:r></a:p>'
            .'<a:p><a:r><a:t>增长 20%</a:t></a:r></a:p></p:sld>';
        $zip->addFromString('ppt/slides/slide1.xml', $slide);
        $zip->addFromString('ppt/slides/slide2.xml', str_replace('季度总结', '下一步计划', $slide));
        $zip->close();

        $attachment = $this->makeAttachment($user, '汇报.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', (string) file_get_contents($path));
        unlink($path);

        $this->actingAs($user)->get(route('attachments.preview', $attachment))
            ->assertOk()
            ->assertSee('第 1 页')
            ->assertSee('第 2 页')
            ->assertSee('季度总结')
            ->assertSee('下一步计划');
    }

    public function test_zip_lists_its_entries(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'diary-zip-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('照片/one.jpg', 'x');
        $zip->close();

        $attachment = $this->makeAttachment($user, '打包.zip', 'application/zip', (string) file_get_contents($path));
        unlink($path);

        $this->actingAs($user)->get(route('attachments.preview', $attachment))
            ->assertOk()
            ->assertSee('preview-list', false)
            ->assertSee('照片/one.jpg');
    }

    public function test_legacy_office_without_libreoffice_falls_back_to_download(): void
    {
        Storage::fake('local');
        config()->set('diary.preview.libreoffice', '/nonexistent/soffice');
        $user = User::factory()->create();
        $attachment = $this->makeAttachment($user, '旧版.doc', 'application/msword', 'binary');

        $this->actingAs($user)->get(route('attachments.preview', $attachment))
            ->assertOk()
            ->assertSee('请下载后查看')
            ->assertSee('下载文件');
    }

    public function test_legacy_office_is_converted_to_pdf_when_libreoffice_is_available(): void
    {
        Storage::fake('local');

        $binary = tempnam(sys_get_temp_dir(), 'fake-soffice-');
        $script = <<<'SH'
#!/bin/sh
while [ "$#" -gt 0 ]; do
    case "$1" in
        --outdir) outdir="$2"; shift 2 ;;
        *) shift ;;
    esac
done
printf '%%PDF-1.4 converted' > "${outdir}/source.pdf"
SH;
        file_put_contents($binary, $script);
        chmod($binary, 0700);
        config()->set('diary.preview.libreoffice', $binary);

        $user = User::factory()->create();
        $attachment = $this->makeAttachment($user, '旧版.doc', 'application/msword', 'binary');

        $this->actingAs($user)->get(route('attachments.preview', $attachment))
            ->assertOk()
            ->assertSee('preview-frame', false)
            ->assertSee('已在本机转换为 PDF 预览')
            ->assertSee(route('attachments.preview-file', $attachment), false);

        $this->actingAs($user)->get(route('attachments.preview-file', $attachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs(User::factory()->create())
            ->get(route('attachments.preview-file', $attachment))
            ->assertNotFound();

        unlink($binary);
    }

    public function test_preview_is_private_to_its_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $attachment = $this->makeAttachment($owner, '私密.txt', 'text/plain', '秘密');

        $this->actingAs($other)->get(route('attachments.preview', $attachment))->assertNotFound();
        $this->actingAs($other)->get(route('attachments.preview-file', $attachment))->assertNotFound();
        $this->actingAs($owner)->get(route('attachments.preview', $attachment))->assertOk();
    }

    public function test_editor_and_reader_link_to_preview(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $entry = Entry::create([
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
            'title' => '带附件',
            'body' => '正文',
            'status' => 'published',
        ]);
        $attachment = $this->makeAttachment($user, '合同.pdf', 'application/pdf', '%PDF-1.4', null, $entry);

        $this->actingAs($user)->get('/diary/create?date=2026-11-27')
            ->assertOk()->assertSee(route('attachments.preview', $attachment), false);
        $this->actingAs($user)->get('/diary/2026-11-27')
            ->assertOk()->assertSee(route('attachments.preview', $attachment), false);
    }

    private function makeAttachment(
        User $user,
        string $name,
        string $mime,
        string $contents,
        ?string $kind = null,
        ?Entry $entry = null,
    ): Attachment {
        $entry ??= Entry::firstOrCreate([
            'user_id' => $user->id,
            'entry_date' => '2026-11-27',
        ], [
            'title' => '附件测试',
            'body' => '正文',
            'status' => 'draft',
        ]);
        $path = 'attachments/test/'.$name;
        Storage::disk('local')->put($path, $contents);

        return Attachment::create([
            'user_id' => $user->id,
            'entry_id' => $entry->id,
            'path' => $path,
            'original_name' => $name,
            'mime' => $mime,
            'size' => strlen($contents),
            'kind' => $kind ?? 'file',
        ]);
    }
}
