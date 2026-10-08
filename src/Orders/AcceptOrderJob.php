<?php

namespace Nosh\OmniConnect\Orders;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Nosh\OmniConnect\OmniConnectManager;

/**
 * The auto-accept an IncomingOrderHandler asked for, made off the webhook.
 *
 * The dispatch webhook has to answer fast, and an accept is a login plus a
 * round trip to the platform. Doing it inline also meant a slow or failing
 * platform turned OUR acknowledgement into a 5xx, so the platform re-sent an
 * order we had already taken.
 *
 * Carries the tenant id, never the credentials: the queue payload is stored in
 * plain text, and a password has no business sitting in Redis.
 */
class AcceptOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    /** @var array<int,int> */
    public array $backoff;

    public function __construct(
        public string $orderToken,
        public mixed $tenantId = null,
        public ?string $platform = null,
    ) {
        $config = config('omniconnect', []);
        $this->tries = max(1, (int) ($config['retry_attempts'] ?? 3));
        $this->backoff = (array) ($config['retry_backoff'] ?? [10, 30, 120]);
        $this->onQueue($config['queue'] ?? null);
    }

    public function handle(OmniConnectManager $manager): void
    {
        $manager = $this->platform ? $manager->platform($this->platform) : $manager;
        $manager = $this->tenantId !== null ? $manager->for($this->tenantId) : $manager;

        $manager->orders()->accept($this->orderToken);
    }
}
