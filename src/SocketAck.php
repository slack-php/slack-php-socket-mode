<?php

declare(strict_types=1);

namespace SlackPhp\SocketMode;

use JsonException;
use SlackPhp\Framework\Exception;

/**
 * Represents an Ack for Socket Mode.
 *
 * Takes care to ensure the data is encoded properly.
 */
class SocketAck
{
    private string $envelopeId;
    private ?array $payload;

    public function __construct(string $envelopeId, ?string $payload = null)
    {
        $this->envelopeId = $envelopeId;
        $this->payload = $this->decodePayload($payload);
    }

    private function decodePayload(?string $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new Exception('Invalid ack payload JSON encountered while decoding', 0, $exception);
        }
    }

    private function encodeAck(): string
    {
        $data = [];
        $data['envelope_id'] = $this->envelopeId;
        if ($this->payload !== null) {
            $data['payload'] = $this->payload;
        }

        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new Exception('Invalid ack JSON encountered while encoding', 0, $exception);
        }
    }

    public function __toString(): string
    {
        return $this->encodeAck();
    }
}
