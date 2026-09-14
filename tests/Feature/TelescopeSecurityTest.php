<?php

use App\Models\Customer;
use App\Providers\TelescopeServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps telescope disabled unless explicitly enabled', function () {
    expect(config('telescope.enabled'))->toBeFalse();
});

it('requires an authenticated allow-listed operator when enabled', function () {
    config()->set('telescope.enabled', true);
    config()->set('telescope.allowed_emails', 'operator@example.test');
    app()->register(TelescopeServiceProvider::class);

    $operator = Customer::factory()->create(['email' => 'operator@example.test']);
    $other = Customer::factory()->create(['email' => 'other@example.test']);

    expect(Gate::forUser($operator)->allows('viewTelescope'))->toBeTrue()
        ->and(Gate::forUser($other)->allows('viewTelescope'))->toBeFalse();
});
