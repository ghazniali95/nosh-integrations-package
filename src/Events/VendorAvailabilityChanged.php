<?php

namespace Nosh\OmniConnect\Events;

/**
 * The platform notified us a vendor's availability changed.
 * `closures` empty ⇒ open; otherwise the vendor is (or will be) closed.
 */
class VendorAvailabilityChanged
{
    public function __construct(
        public string $remoteId,
        public ?string $timestamp,
        /** @var array<int,array> */
        public array $closures = [],
        public array $raw = [],
    ) {
    }

    public function isOpen(): bool
    {
        return $this->closures === [];
    }
}
