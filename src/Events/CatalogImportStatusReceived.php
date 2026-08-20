<?php

namespace Nosh\OmniConnect\Events;

/** The platform reported the outcome of a catalog import we submitted. */
class CatalogImportStatusReceived
{
    public function __construct(
        public ?string $catalogImportId,
        /** in_progress | done | done_with_errors | failed */
        public ?string $status,
        public ?string $message = null,
        public array $details = [],
        public array $raw = [],
    ) {
    }
}
