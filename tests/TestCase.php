<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function fakeDocumentsDisk(): LocalFilesystemAdapter
    {
        // Separate tests and concurrent runners must never delete each other's files.
        $name = 'documents-'.Str::uuid();
        $disk = Storage::fake($name, [
            'throw' => (bool) config('filesystems.disks.documents.throw', true),
        ]);
        Storage::set('documents', $disk);

        $root = storage_path('framework/testing/disks/'.$name);
        $this->beforeApplicationDestroyed(function () use ($root): void {
            (new Filesystem)->deleteDirectory($root);
        });

        return $disk;
    }
}
