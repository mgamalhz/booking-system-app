<?php

namespace App\Repositories;

use App\Models\Customer;
use App\Repositories\Interfaces\CustomerRepositoryInterface;

class CustomerRepository implements CustomerRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Customer
    {
        /** @var Customer $customer */
        $customer = Customer::query()->create($data);

        return $customer;
    }

    public function findByEmail(string $email): ?Customer
    {
        /** @var Customer|null $customer */
        $customer = Customer::query()->where('email', $email)->first();

        return $customer;
    }
}
