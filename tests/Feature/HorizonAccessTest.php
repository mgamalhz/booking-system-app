<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    app()->detectEnvironment(fn (): string => 'testing');
});

test('horizon allows the admin user outside local environments', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $admin = Customer::factory()->create(['email' => 'dmin@admin.com']);

    $this->actingAs($admin)
        ->get('/horizon')
        ->assertOk();
});

test('horizon is forbidden for other users outside local environments', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $user = Customer::factory()->create(['email' => 'user@example.com']);

    $this->actingAs($user)
        ->get('/horizon')
        ->assertForbidden();
});
