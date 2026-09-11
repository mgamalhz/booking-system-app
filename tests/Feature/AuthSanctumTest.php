<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('it registers a customer and returns a sanctum token', function () {
    $response = $this->postJson(route('auth.register'), [
        'name' => 'Yasser',
        'email' => 'yasser@example.com',
        'password' => 'password123',
    ])
        ->assertCreated()
        ->assertJsonStructure([
            'customer' => ['id', 'name', 'email'],
            'token',
        ]);

    $customer = Customer::query()->where('email', 'yasser@example.com')->firstOrFail();

    expect(Hash::check('password123', $customer->password))->toBeTrue()
        ->and($response->json('token'))->toContain('|');
});

test('it logs in a customer and returns a sanctum token', function () {
    $customer = Customer::factory()->create([
        'email' => 'login@example.com',
        'password' => Hash::make('password123'),
    ]);

    $response = $this->postJson(route('auth.login'), [
        'email' => 'login@example.com',
        'password' => 'password123',
    ])
        ->assertOk()
        ->assertJsonPath('customer.id', $customer->id)
        ->assertJsonStructure([
            'customer' => ['id', 'name', 'email'],
            'token',
        ]);

    expect($response->json('token'))->toContain('|');
});

test('it rejects invalid login credentials', function () {
    Customer::factory()->create([
        'email' => 'login@example.com',
        'password' => Hash::make('password123'),
    ]);

    $this->postJson(route('auth.login'), [
        'email' => 'login@example.com',
        'password' => 'wrong-password',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['email']]]]);
});
