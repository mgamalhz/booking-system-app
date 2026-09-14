<?php

namespace App\Providers;

use App\Events\BookingConfirmed;
use App\Listeners\BookingConfirmationNotificationListener;
use App\Listeners\LogConfirmedBooking;
use App\Listeners\SendFailedJobAlert;
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
        Event::listen(BookingConfirmed::class, [BookingConfirmationNotificationListener::class, 'handle']);
        Event::listen(BookingConfirmed::class, [LogConfirmedBooking::class, 'handle']);
        Event::listen(JobFailed::class, [SendFailedJobAlert::class, 'handle']);

    }
}
