<?php

namespace App\Providers;

use App\Events\BookingCancelled;
use App\Events\BookingCompleted;
use App\Events\BookingConfirmed;
use App\Jobs\SendBookingConfirmation;
use App\Listeners\InvalidateBookingAvailabilityCache;
use App\Listeners\LogConfirmedBooking;
use App\Listeners\RecordBookingStatusEvent;
use App\Listeners\SendFailedJobAlert;
use App\Models\Booking;
use App\Repositories\BookingDocumentRepository;
use App\Repositories\BookingRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\Interfaces\BookingCancellationRepositoryInterface;
use App\Repositories\Interfaces\BookingDocumentRepositoryInterface;
use App\Repositories\Interfaces\BookingRepositoryInterface;
use App\Repositories\Interfaces\CustomerRepositoryInterface;
use App\Services\Contracts\FilesUploadServiceInterface;
use App\Services\S3FilesUploadService;
use App\Strategies\BookingStrategies\BookingStrategyInterface;
use App\Strategies\BookingStrategies\BookingStrategyResolver;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @throws \ReflectionException
     */
    public function register(): void
    {
        if (config('telescope.enabled') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(TelescopeServiceProvider::class);
        }

        App::bind(BookingRepositoryInterface::class, BookingRepository::class);
        App::bind(BookingCancellationRepositoryInterface::class, BookingRepository::class);
        App::bind(BookingDocumentRepositoryInterface::class, BookingDocumentRepository::class);
        App::bind(CustomerRepositoryInterface::class, CustomerRepository::class);
        App::bind(BookingStrategyInterface::class, BookingStrategyResolver::class);
        App::bind(FilesUploadServiceInterface::class, S3FilesUploadService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([BookingConfirmed::class, BookingCancelled::class, BookingCompleted::class] as $event) {
            Event::listen($event, [RecordBookingStatusEvent::class, 'handle']);
            Event::listen($event, [InvalidateBookingAvailabilityCache::class, 'handle']);
            Event::listen($event, [LogConfirmedBooking::class, 'handle']);
        }

        Event::listen(BookingConfirmed::class, function (BookingConfirmed $event): void {
            $booking = $event->booking ?? Booking::query()->findOrFail($event->bookingId);

            SendBookingConfirmation::dispatch($booking)->afterCommit();
        });

        Event::listen(JobFailed::class, [SendFailedJobAlert::class, 'handle']);

    }
}
