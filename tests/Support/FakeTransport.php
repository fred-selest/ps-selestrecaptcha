<?php

declare(strict_types=1);

namespace SelestRecaptcha\Tests\Support;

use SelestRecaptcha\Http\TransportInterface;
use SelestRecaptcha\Http\TransportException;

/**
 * Transport that answers from a script and remembers what it was sent.
 */
final class FakeTransport implements TransportInterface
{
    /** @var array<int, array{url: string, fields: array<string, string>}> */
    public array $calls = [];

    public bool $shouldThrow = false;

    /** Answer used for every call once the queue is empty. */
    public string $body = '{"success":true}';

    /** @var array<int, string> */
    public array $queue = [];

    public function post(string $url, array $fields, int $timeoutSeconds): string
    {
        $this->calls[] = ['url' => $url, 'fields' => $fields];

        if ($this->shouldThrow) {
            throw new TransportException('network is down');
        }

        if ($this->queue !== []) {
            return (string) array_shift($this->queue);
        }

        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function lastFields(): array
    {
        $last = end($this->calls);

        return $last === false ? [] : $last['fields'];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}