<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Builds a privacy-safe preview payload for an attachment.
 *
 * Everything is parsed locally: no file or excerpt is ever handed to a
 * third-party preview service. XML is read with the network disabled and
 * DOCTYPE/ENTITY declarations are rejected; every parser has hard limits so a
 * crafted document cannot exhaust memory. XPath uses local-name() so it does
 * not depend on namespace prefixes declared inside each vendor's XML.
 */
class DocumentPreview
{
    private const MAX_TEXT_BYTES = 512 * 1024;

    private const MAX_TEXT_LINES = 2000;

    private const MAX_LINE_BYTES = 16 * 1024;

    private const MAX_TABLE_ROWS = 500;

    private const MAX_TABLE_COLS = 30;

    private const MAX_TABLE_CELLS = 6000;

    private const MAX_PARAGRAPHS = 1200;

    private const MAX_SLIDES = 60;

    private const MAX_SLIDE_LINES = 200;

    private const MAX_ARCHIVE_ENTRIES = 200;

    /**
     * @return array{type: string, download_url: string, notice?: string, url?: string, lines?: list<string>, rows?: list<list<string>>, paragraphs?: list<string>, slides?: list<array{number: int, lines: list<string>}>, names?: list<string>}
     */
    public function preview(Attachment $attachment): array
    {
        $base = ['download_url' => route('attachments.show', $attachment)];
        $extension = strtolower((string) pathinfo($attachment->original_name, PATHINFO_EXTENSION));

        if ($attachment->isImage()) {
            return $base + ['type' => 'image', 'url' => $base['download_url']];
        }

        if ($extension === 'pdf') {
            return $base + ['type' => 'pdf', 'url' => $base['download_url']];
        }

        if ($extension === 'txt' || $extension === 'csv') {
            $lines = $this->readTextLines($attachment);

            return $extension === 'csv'
                ? $base + ['type' => 'csv', 'rows' => $this->parseCsv($lines)]
                : $base + ['type' => 'text', 'lines' => $lines];
        }

        if ($extension === 'zip') {
            return $base + ['type' => 'archive', 'names' => $this->archiveNames($attachment)];
        }

        if (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            $result = $this->parseOfficeXml($attachment, $extension);

            return $result !== null
                ? $base + $result
                : $base + ['type' => 'download', 'notice' => '文件内容无法解析，请下载后查看。'];
        }

        if (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            $url = $this->convertedPdfUrl($attachment);

            return $url !== null
                ? $base + ['type' => 'pdf', 'url' => $url, 'notice' => '已在本机转换为 PDF 预览，原始文件可下载。']
                : $base + [
                    'type' => 'download',
                    'notice' => '旧版 Office 格式需要在服务器安装 LibreOffice 才能在线预览，当前请下载后查看。',
                ];
        }

        return $base + ['type' => 'download', 'notice' => '这种格式暂不支持在线预览，请下载后查看。'];
    }

    public function previewPath(Attachment $attachment): string
    {
        return 'previews/'.$attachment->user_id.'/'.$attachment->id.'.pdf';
    }

    public function deletePreview(Attachment $attachment): void
    {
        Storage::disk('local')->delete($this->previewPath($attachment));
    }

    /**
     * @return list<string>
     */
    private function readTextLines(Attachment $attachment): array
    {
        $stream = Storage::disk('local')->readStream($attachment->path);

        if ($stream === false) {
            return [];
        }

        $bytes = 0;
        $chunks = [];

        try {
            while (($line = fgets($stream)) !== false && $bytes < self::MAX_TEXT_BYTES && count($chunks) < self::MAX_TEXT_LINES) {
                if (strlen($line) > self::MAX_LINE_BYTES) {
                    $line = mb_strcut($line, 0, self::MAX_LINE_BYTES, 'UTF-8');
                }

                $bytes += strlen($line);
                $chunks[] = $line;
            }
        } finally {
            fclose($stream);
        }

        $text = implode('', $chunks);

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', ['GB18030', 'Big5', 'ISO-8859-1']);
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return array_slice(explode("\n", $text), 0, self::MAX_TEXT_LINES);
    }

    /**
     * @param  list<string>  $lines
     * @return list<list<string>>
     */
    private function parseCsv(array $lines): array
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, implode("\n", $lines));
        rewind($handle);

        $rows = [];
        $cells = 0;

        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false && count($rows) < self::MAX_TABLE_ROWS) {
                $row = array_map(
                    static fn ($cell): string => is_string($cell) ? $cell : '',
                    array_slice($row, 0, self::MAX_TABLE_COLS),
                );
                $cells += count($row);

                if ($cells > self::MAX_TABLE_CELLS) {
                    $rows[] = ['… 内容过长，已截断'];

                    break;
                }

                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @return array{type: string, paragraphs?: list<string>, rows?: list<list<string>>, slides?: list<array{number: int, lines: list<string>}>}|null
     */
    private function parseOfficeXml(Attachment $attachment, string $extension): ?array
    {
        $zip = $this->openZip($attachment);

        if ($zip === null) {
            return null;
        }

        try {
            return match ($extension) {
                'docx' => $this->parseDocx($zip),
                'xlsx' => $this->parseXlsx($zip),
                'pptx' => $this->parsePptx($zip),
                default => null,
            };
        } finally {
            $zip->close();
        }
    }

    private function openZip(Attachment $attachment): ?ZipArchive
    {
        $path = Storage::disk('local')->path($attachment->path);

        if (! is_file($path)) {
            return null;
        }

        $zip = new ZipArchive();

        return $zip->open($path) === true ? $zip : null;
    }

    /**
     * @return array{type: string, paragraphs: list<string>}|null
     */
    private function parseDocx(ZipArchive $zip): ?array
    {
        $xml = $zip->getFromName('word/document.xml');

        if ($xml === false || ($document = $this->loadXml($xml)) === null) {
            return null;
        }

        $paragraphs = [];

        foreach ($this->find($document, 'p') as $paragraph) {
            if (count($paragraphs) >= self::MAX_PARAGRAPHS) {
                $paragraphs[] = '… 内容过长，已截断';

                break;
            }

            $text = trim(str_replace(["\t", "\u{00a0}"], ' ', $this->findText($paragraph)));

            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        return ['type' => 'docx', 'paragraphs' => $paragraphs];
    }

    /**
     * @return array{type: string, rows: list<list<string>>}|null
     */
    private function parseXlsx(ZipArchive $zip): ?array
    {
        $shared = $this->parseSharedStrings($zip);
        $sheetPath = $this->firstSheetPath($zip);

        if ($sheetPath === null) {
            return null;
        }

        $xml = $zip->getFromName($sheetPath);

        if ($xml === false || ($sheet = $this->loadXml($xml)) === null) {
            return null;
        }

        $rows = [];
        $cells = 0;

        foreach ($this->find($sheet, 'row') as $node) {
            if (count($rows) >= self::MAX_TABLE_ROWS || $cells > self::MAX_TABLE_CELLS) {
                $rows[] = ['… 内容过长，已截断'];

                break;
            }

            $row = [];

            foreach ($this->find($node, 'c') as $cell) {
                $column = $this->columnIndex((string) $cell['r']);

                if ($column >= self::MAX_TABLE_COLS) {
                    continue;
                }

                $type = (string) $cell['t'];
                $valueNodes = $this->find($cell, 'v');

                if ($type === 's') {
                    $value = $shared[(int) (string) ($valueNodes[0] ?? '')] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = $this->findText($cell);
                } else {
                    $value = trim((string) ($valueNodes[0] ?? ''));
                }

                while (count($row) < $column) {
                    $row[] = '';
                }

                $row[$column] = $value;
                $cells++;
            }

            $rows[] = $row;
        }

        return ['type' => 'xlsx', 'rows' => $rows];
    }

    /**
     * @return array<int, string>
     */
    private function parseSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false || ($root = $this->loadXml($xml)) === null) {
            return [];
        }

        $strings = [];

        foreach ($this->find($root, 'si') as $item) {
            $strings[] = $this->findText($item);
        }

        return $strings;
    }

    private function firstSheetPath(ZipArchive $zip): ?string
    {
        for ($index = 1; $index <= 3; $index++) {
            $candidate = 'xl/worksheets/sheet'.$index.'.xml';

            if ($zip->locateName($candidate) !== false) {
                return $candidate;
            }
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml')) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array{type: string, slides: list<array{number: int, lines: list<string>}>}|null
     */
    private function parsePptx(ZipArchive $zip): ?array
    {
        $slides = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (! preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $matches)) {
                continue;
            }

            if (count($slides) >= self::MAX_SLIDES) {
                break;
            }

            $xml = $zip->getFromName($name);

            if ($xml === false || ($slide = $this->loadXml($xml)) === null) {
                continue;
            }

            $lines = [];

            foreach ($this->find($slide, 'p') as $paragraph) {
                if (count($lines) >= self::MAX_SLIDE_LINES) {
                    $lines[] = '… 内容过长，已截断';

                    break;
                }

                $text = trim($this->findText($paragraph));

                if ($text !== '') {
                    $lines[] = $text;
                }
            }

            $slides[] = ['number' => (int) $matches[1], 'lines' => $lines];
        }

        usort($slides, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        return ['type' => 'pptx', 'slides' => $slides];
    }

    /**
     * @return list<string>
     */
    private function archiveNames(Attachment $attachment): array
    {
        $zip = $this->openZip($attachment);

        if ($zip === null) {
            return [];
        }

        $names = [];

        try {
            for ($index = 0; $index < $zip->numFiles && count($names) < self::MAX_ARCHIVE_ENTRIES; $index++) {
                $names[] = (string) $zip->getNameIndex($index);
            }
        } finally {
            $zip->close();
        }

        return $names;
    }

    private function convertedPdfUrl(Attachment $attachment): ?string
    {
        $path = $this->previewPath($attachment);

        if (! Storage::disk('local')->exists($path) && ! $this->convertToPdf($attachment, $path)) {
            return null;
        }

        return route('attachments.preview-file', $attachment);
    }

    private function convertToPdf(Attachment $attachment, string $target): bool
    {
        $binary = (string) config('diary.preview.libreoffice', '');

        if ($binary === '' || ! is_executable($binary)) {
            return false;
        }

        $extension = strtolower((string) pathinfo($attachment->original_name, PATHINFO_EXTENSION));
        $tempDir = sys_get_temp_dir().'/diary-preview-'.bin2hex(random_bytes(8));
        $source = $tempDir.'/source.'.$extension;

        if (! mkdir($tempDir, 0700, true)) {
            return false;
        }

        try {
            $contents = Storage::disk('local')->get($attachment->path);

            if ($contents === null || file_put_contents($source, $contents) === false) {
                return false;
            }

            // soffice needs a writable HOME for its user profile. php-fpm runs as
            // www-data, whose home is usually not writable, so point it at our temp dir.
            $process = new Process(
                [$binary, '--headless', '--convert-to', 'pdf', '--outdir', $tempDir, $source],
                $tempDir,
                ['HOME' => $tempDir],
            );
            $process->setTimeout(120);
            $process->run();

            $pdf = $tempDir.'/source.pdf';

            if (! $process->isSuccessful() || ! is_file($pdf)) {
                return false;
            }

            $contents = file_get_contents($pdf);

            if ($contents === false) {
                return false;
            }

            Storage::disk('local')->put($target, $contents);

            return true;
        } finally {
            @unlink($source);
            @unlink($tempDir.'/source.pdf');
            @rmdir($tempDir);
        }
    }

    private function loadXml(string $xml): ?SimpleXMLElement
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

            return $document === false ? null : $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function find(SimpleXMLElement $node, string $tag): array
    {
        return $node->xpath('.//*[local-name()="'.$tag.'" and namespace-uri()!=""]') ?: [];
    }

    private function findText(SimpleXMLElement $node): string
    {
        return implode('', array_map(
            static fn (SimpleXMLElement $text): string => (string) $text,
            $this->find($node, 't'),
        ));
    }

    private function columnIndex(string $reference): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Z]/', '', $reference));
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
