<?php

namespace App\Repositories\Interfaces;

use App\Models\Customer;

interface CustomerRepositoryInterface
{
    public function create(array $data): Customer;

    public function findByEmail(string $email): ?Customer;
}
