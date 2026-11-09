<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Uploads live on the private "documents" disk (storage/app/documents) and are only
 * served through DocumentController after an access check.
 */
class DocumentStore
{
    public const DISK = 'documents';

    public function store(UploadedFile $file, string $folder): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $name = now()->format('YmdHis').'-'.Str::random(12).'.'.$extension;

        return $file->storeAs(trim($folder, '/'), $name, self::DISK);
    }

    public function put(string $path, string $contents): string
    {
        Storage::disk(self::DISK)->put($path, $contents);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk(self::DISK)->exists($path);
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }
}
