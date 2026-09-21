<?php

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('it stores an uploaded booking document and writes the database row', function () {
    Storage::fake('documents');

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->create([
        'customer_id' => $customer->id,
    ]);
    $file = UploadedFile::fake()->create('passport.pdf', 256, 'application/pdf');

    $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.upload', $booking), [
            'attachment' => $file,
        ])
        ->assertCreated()
        ->assertJsonPath('document.booking_id', $booking->id)
        ->assertJsonPath('document.disk', 'documents');

    $document = BookingDocument::query()->firstOrFail();

    expect($document->booking_id)->toBe($booking->id)
        ->and($document->disk)->toBe('documents')
        ->and($document->original_name)->toBe('passport.pdf')
        ->and($document->mime)->toBe('application/pdf')
        ->and($document->size)->toBe($file->getSize())
        ->and($document->key)->toStartWith("bookings/{$booking->id}/documents/")
        ->and($document->key)->toEndWith('.pdf');

    Storage::disk('documents')->assertExists($document->key);
});

test('it rejects unsupported document file types', function () {
    Storage::fake('documents');

    $customer = Customer::factory()->create();
    $booking = Booking::factory()->create([
        'customer_id' => $customer->id,
    ]);

    $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.upload', $booking), [
            'attachment' => UploadedFile::fake()->create('payload.txt', 4, 'text/plain'),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['attachment']]]]);

    expect(BookingDocument::query()->count())->toBe(0);
    Storage::disk('documents')->assertMissing("bookings/{$booking->id}/documents/payload.txt");
});

test('it rejects document uploads for bookings owned by another user', function () {
    Storage::fake('documents');

    $booking = Booking::factory()->create([
        'customer_id' => Customer::factory()->create()->id,
    ]);

    $this->actingAs(Customer::factory()->create(), 'sanctum')
        ->postJson(route('bookings.upload', $booking), [
            'attachment' => UploadedFile::fake()->create('passport.pdf', 256, 'application/pdf'),
        ])
        ->assertForbidden();

    expect(BookingDocument::query()->count())->toBe(0);
    Storage::disk('documents')->assertMissing("bookings/{$booking->id}/documents/passport.pdf");
});
