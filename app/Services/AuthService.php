<?php

namespace App\Services;

use App\Models\Customer;
use App\Repositories\Interfaces\CustomerRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private CustomerRepositoryInterface $customerRepository) {}

    /**
     * @param  array{name: string, email: string, password: string}  $data
     * @return array{customer: Customer, token: string}
     */
    public function register(array $data): array
    {
        $customer = $this->customerRepository->create($data);

        return $this->tokenResponse($customer);
    }

    /**
     * @param  array{email: string, password: string}  $data
     * @return array{customer: Customer, token: string}
     */
    public function login(array $data): array
    {
        $customer = $this->customerRepository->findByEmail($data['email']);

        if (! $customer || ! is_string($customer->password) || ! Hash::check($data['password'], $customer->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $this->tokenResponse($customer);
    }

    /**
     * @return array{customer: Customer, token: string}
     */
    private function tokenResponse(Customer $customer): array
    {
        return [
            'customer' => $customer,
            'token' => $customer->createToken('api')->plainTextToken,
        ];
    }
}
