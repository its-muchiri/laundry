<?php

namespace Laundry\Core;

/**
 * Thin wrapper over the incoming HTTP request: method, path, JSON body,
 * query params, and the authenticated-user context set by auth middleware
 * (not implemented in this scaffold — see planning/00-portfolio/shared-architecture.md
 * for the shared identity/auth module this should eventually delegate to).
 */
final class Request
{
    public string $method;
    public string $path;
    /** @var array<string,mixed> */
    public array $query;
    /** @var array<string,mixed> */
    public array $body;
    /** @var array<string,mixed> route parameters extracted by the Router, e.g. ['id' => '42'] */
    public array $params = [];
    /** @var array<string,mixed>|null set by auth middleware once implemented */
    public ?array $user = null;

    public function __construct(string $method, string $path, array $query, array $body)
    {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $rawBody = file_get_contents('php://input') ?: '';
        $decoded = json_decode($rawBody, true);
        $body = is_array($decoded) ? $decoded : [];

        return new self($method, $path, $_GET, $body);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }
}
