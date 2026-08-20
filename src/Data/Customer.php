<?php

namespace Nosh\OmniConnect\Data;

/**
 * Canonical customer on an order. Delivery platforms typically mask/proxy
 * contact details; keep whatever the payload provides.
 */
class Customer
{
    public function __construct(
        public ?string $name = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $address = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'name'    => $this->name,
            'phone'   => $this->phone,
            'email'   => $this->email,
            'address' => $this->address,
        ];
    }

    public static function fromArray(array $d): self
    {
        // Delivery Hero splits the name and uses `mobilePhone`.
        $name = $d['name'] ?? trim(($d['firstName'] ?? '').' '.($d['lastName'] ?? '')) ?: null;

        return new self(
            name: $name,
            phone: $d['mobilePhone'] ?? $d['phone'] ?? $d['mobile'] ?? null,
            email: $d['email'] ?? null,
            address: $d['address'] ?? null,
        );
    }
}
