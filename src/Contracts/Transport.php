<?php

namespace Nosh\OmniConnect\Contracts;

/**
 * Abstracts the actual network call so a driver can run against real HTTPS
 * or against an in-package mock (no credentials / no network) for testing.
 *
 * Every method returns: array{status:int, body:array}
 */
interface Transport
{
    /** JSON POST (application/json). */
    public function post(string $url, array $payload, array $headers = []): array;

    /** JSON PUT (application/json) — used for catalog submission. */
    public function put(string $url, array $payload, array $headers = []): array;

    /** GET with query params. */
    public function get(string $url, array $query = [], array $headers = []): array;

    /**
     * Form-encoded POST (application/x-www-form-urlencoded).
     * Delivery Hero's /v2/login expects this content type.
     */
    public function postForm(string $url, array $params, array $headers = []): array;

    /**
     * Raw-body request with an explicit method + Content-Type header
     * (e.g. the deprecated XML menu-import endpoints).
     */
    public function sendRaw(string $method, string $url, string $rawBody, array $headers = []): array;
}
