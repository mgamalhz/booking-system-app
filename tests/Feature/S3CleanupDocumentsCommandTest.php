<?php

use App\Models\Booking;
use App\Models\BookingDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'filesystems.disks.documents.orphan_cleanup_grace_minutes' => 0,
    ]);
});

test('it removes orphaned and expired soft deleted booking document objects only', function () {
    Storage::fake('documents');

    $booking = Booking::factory()->create();

    $liveKey = "bookings/{$booking->id}/documents/live.pdf";
    $recentlyDeletedKey = "bookings/{$booking->id}/documents/recently-deleted.pdf";
    $expiredDeletedKey = "bookings/{$booking->id}/documents/expired-deleted.pdf";
    $orphanedKey = "bookings/{$booking->id}/documents/orphaned.pdf";

    Storage::disk('documents')->put($liveKey, 'live');
    Storage::disk('documents')->put($recentlyDeletedKey, 'recently deleted');
    Storage::disk('documents')->put($expiredDeletedKey, 'expired deleted');
    Storage::disk('documents')->put($orphanedKey, 'orphaned');

    createBookingDocument($booking, $liveKey);

    $recentlyDeletedDocument = createBookingDocument($booking, $recentlyDeletedKey);
    $recentlyDeletedDocument->delete();

    $expiredDeletedDocument = createBookingDocument($booking, $expiredDeletedKey);
    $expiredDeletedDocument->delete();
    $expiredDeletedDocument->forceFill([
        'deleted_at' => now()->subDays(4),
    ])->save();

    Log::spy();

    $this->artisan('s3:cleanup-documents', [
        '--retention-days' => 3,
    ])->assertSuccessful();

    Storage::disk('documents')->assertExists($liveKey);
    Storage::disk('documents')->assertExists($recentlyDeletedKey);
    Storage::disk('documents')->assertMissing($expiredDeletedKey);
    Storage::disk('documents')->assertMissing($orphanedKey);

    expect(BookingDocument::withTrashed()->where('key', $liveKey)->exists())->toBeTrue()
        ->and(BookingDocument::withTrashed()->where('key', $recentlyDeletedKey)->exists())->toBeTrue()
        ->and(BookingDocument::withTrashed()->where('key', $expiredDeletedKey)->exists())->toBeFalse();

    Log::shouldHaveReceived('info')
        ->once()
        ->with('S3 booking document cleanup completed.', Mockery::on(
            fn (array $context): bool => $context['retention_days'] === 3
                && $context['expired_soft_deleted_documents_deleted'] === 1
                && $context['orphaned_document_objects_deleted'] === 1
                && $context['total_document_objects_deleted'] === 2
        ));
});

test('it is idempotent when a deletable object is already missing', function () {
    Storage::fake('documents');

    $booking = Booking::factory()->create();
    $missingKey = "bookings/{$booking->id}/documents/already-gone.pdf";

    $document = createBookingDocument($booking, $missingKey);
    $document->delete();
    $document->forceFill([
        'deleted_at' => now()->subDays(4),
    ])->save();

    $this->artisan('s3:cleanup-documents', [
        '--retention-days' => 3,
    ])->assertSuccessful();

    Storage::disk('documents')->assertMissing($missingKey);
    expect(BookingDocument::withTrashed()->where('key', $missingKey)->exists())->toBeFalse();
});

test('it does not remove orphaned document objects inside the grace window', function () {
    config([
        'filesystems.disks.documents.orphan_cleanup_grace_minutes' => 15,
    ]);

    Storage::fake('documents');

    $booking = Booking::factory()->create();
    $recentOrphanedKey = "bookings/{$booking->id}/documents/recent-orphaned.pdf";

    Storage::disk('documents')->put($recentOrphanedKey, 'upload in flight');

    Log::spy();

    $this->artisan('s3:cleanup-documents', [
        '--retention-days' => 3,
    ])->assertSuccessful();

    Storage::disk('documents')->assertExists($recentOrphanedKey);

    Log::shouldHaveReceived('info')
        ->once()
        ->with('S3 booking document cleanup completed.', Mockery::on(
            fn (array $context): bool => $context['retention_days'] === 3
                && $context['orphan_cleanup_grace_minutes'] === 15
                && $context['expired_soft_deleted_documents_deleted'] === 0
                && $context['orphaned_document_objects_deleted'] === 0
                && $context['total_document_objects_deleted'] === 0
        ));
});

function createBookingDocument(Booking $booking, string $key): BookingDocument
{
    return BookingDocument::query()->create([
        'booking_id' => $booking->id,
        'disk' => 'documents',
        'key' => $key,
        'original_name' => basename($key),
        'mime' => 'application/pdf',
        'size' => 128,
    ]);
}
