<?php

declare(strict_types=1);

namespace SlackPhp\SocketMode;

use JsonException;
use SlackPhp\Framework\Exception;
use Throwable;

class SocketEnvelope
{
    private const TYPE_UNKNOWN = 0;
    private const TYPE_CONNECTION = 1;
    private const TYPE_DISCONNECT = 2;
    private const TYPE_RECONNECT = 3;
    private const TYPE_EVENT = 4;
    private const TYPE_MAP = [
        'unknown'        => self::TYPE_UNKNOWN,
        'hello'          => self::TYPE_CONNECTION,
        'disconnect'     => self::TYPE_DISCONNECT,
        'slash_commands' => self::TYPE_EVENT,
        'interactive'    => self::TYPE_EVENT,
        'events_api'     => self::TYPE_EVENT,
    ];

    protected array $data;
    protected int $type;

    public function __construct(string $content)
    {
        try {
            $this->data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->throwException('Invalid envelope JSON', $exception);
        }

        $this->type = self::TYPE_MAP[$this->data['type'] ?? 'unknown'] ?? self::TYPE_UNKNOWN;
        if ($this->type === self::TYPE_UNKNOWN) {
            $this->throwException('Cannot determine type of envelope');
        }

        if ($this->type === self::TYPE_DISCONNECT
            && isset($this->data['reason'])
            && in_array($this->data['reason'], ['warning', 'refresh_requested'], true)
        ) {
            $this->type = self::TYPE_RECONNECT;
        }
    }

    public function isConnection(): bool
    {
        return $this->type === self::TYPE_CONNECTION;
    }

    public function isDisconnect(): bool
    {
        return $this->type === self::TYPE_DISCONNECT;
    }

    public function isReconnect(): bool
    {
        return $this->type === self::TYPE_RECONNECT;
    }

    public function isAppEvent(): bool
    {
        return $this->type === self::TYPE_EVENT;
    }

    public function getPayload(): array
    {
        if (!isset($this->data['payload'])) {
            $this->throwException('Payload not available in this type of envelope');
        }

        return $this->data['payload'];
    }

    public function getEnvelopeId(): string
    {
        if (!isset($this->data['envelope_id'])) {
            $this->throwException('Envelope ID not available in this type of envelope');
        }

        return $this->data['envelope_id'];
    }

    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param string $message
     * @param Throwable|null $previous
     * @return never-returns
     */
    private function throwException(string $message, ?Throwable $previous = null): void
    {
        throw new Exception("(Envelope) {$message}", 0, $previous, ['envelope' => $this->data]);
    }
}
