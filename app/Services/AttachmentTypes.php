<?php

namespace App\Services;

class AttachmentTypes
{
    /** @var array<string, string> */
    public const DOCUMENT_MIMES = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
    ];

    /** @var list<string> */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public const IMAGE_EXTENSIONS = 'jpg,jpeg,png,gif,webp';

    public const DOCUMENT_EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip';

    public const MAX_IMAGE_KB = 8192;

    public const MAX_DOCUMENT_KB = 20480;

    public const MAX_PER_UPLOAD = 6;

    /** @var array<string, list<string>> */
    private const EXTENSION_MIMES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
        ],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/x-ole-storage'],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
        ],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'application/csv', 'text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    public static function extensionMatchesMime(string $extension, string $mime): bool
    {
        $extension = strtolower($extension);
        $mime = strtolower($mime);

        return isset(self::EXTENSION_MIMES[$extension])
            && in_array($mime, self::EXTENSION_MIMES[$extension], true);
    }

    public static function kindFor(string $mime): string
    {
        return str_starts_with(strtolower($mime), 'image/') ? 'image' : 'file';
    }

    public static function isAllowed(string $mime): bool
    {
        return in_array($mime, self::IMAGE_MIMES, true) || array_key_exists($mime, self::DOCUMENT_MIMES);
    }

    public static function badge(string $name): string
    {
        $extension = strtoupper((string) pathinfo($name, PATHINFO_EXTENSION));

        return $extension === '' ? 'FILE' : substr($extension, 0, 5);
    }
}
