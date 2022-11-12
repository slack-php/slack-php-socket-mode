<?php

declare(strict_types=1);

namespace SlackPhp\SocketMode;

use Amp;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Websocket;
use Amp\Websocket\Client\Connection;
use SlackPhp\Framework\AppServer;
use SlackPhp\Framework\Context;
use SlackPhp\Framework\Contexts\Payload;
use SlackPhp\Framework\Exception;
use Throwable;

/**
 * Slack app server implementation to support Socket Mode.
 *
 * Socket mode can be used to test a Slack application locally or without the need to expose a public URL to the app.
 */
class SocketServer extends AppServer
{
    private HttpClient $httpClient;
    private Connection $connection;
    private bool $debugReconnects;

    /**
     * Decreases the expiration time of the websocket connections for test/debugging purposes.
     *
     * @return $this
     */
    public function withDebugReconnects(): self
    {
        $this->debugReconnects = true;

        return $this;
    }

    public function init(): void
    {
        $this->httpClient = HttpClientBuilder::buildDefault();
        $this->debugReconnects = false;
    }

    /**
     * Starts receiving and processing events from Slack.
     */
    public function start(): void
    {
        $logger = $this->getLogger();
        Amp\Loop::run(function () use ($logger) {
            try {
                $this->connection = yield $this->createConnection();
                $logger->debug('Socket Mode connection established');
                while ($message = yield $this->connection->receive()) {
                    /** @var Websocket\Message $message */
                    $content = yield $message->buffer();
                    $envelope = new SocketEnvelope($content);
                    if ($envelope->isConnection()) {
                        $logger->debug('Socket Mode connection acknowledged by Slack');
                    } elseif ($envelope->isReconnect()) {
                        $expiredConn = $this->connection;
                        $this->connection = yield $this->createConnection();
                        $logger->debug('Socket Mode connection re-established');
                        $expiredConn->close();
                        $logger->debug('Expired Socket Mode connection closed');
                    } elseif ($envelope->isDisconnect()) {
                        $this->stop();
                    } elseif ($envelope->isAppEvent()) {
                        yield $this->handleSlackAppEvent($envelope);
                    }

                    yield Amp\delay(250);
                }
            } catch (Throwable $exception) {
                $logger->error('Error occurred during Socket Mode', compact('exception'));
                $this->stop();
            }
        });
    }

    public function stop(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }

        $this->getLogger()->debug('Socket Mode connection closed');
        exit(1);
    }

    /**
     * Uses the App to handle the incoming Slack event.
     *
     * @param SocketEnvelope $envelope
     * @return Amp\Promise
     */
    private function handleSlackAppEvent(SocketEnvelope $envelope): Amp\Promise
    {
        return Amp\call(function () use ($envelope) {
            // Create the Context.
            $payload = new Payload($envelope->getPayload());
            $context = new Context($payload, ['envelope' => $envelope]);

            // Let the app handle the Context.
            $app = $this->getApp();
            $app->handle($context);
            if (!$context->isAcknowledged()) {
                throw new Exception('App did not ack for the context');
            }

            // Ack back to Slack through the websocket. The envelope_id must be included.
            $ack = new SocketAck($envelope->getEnvelopeId(), $context->getAck());
            yield $this->connection->send((string) $ack);

            // Pass the context back through the app a second time for any deferred (i.e., post-ack) logic.
            if ($context->isDeferred()) {
                $app->handle($context);
            }
        });
    }

    /**
     * Creates a websocket connection to Slack for the app.
     *
     * @return Amp\Promise<Connection>
     */
    private function createConnection(): Amp\Promise
    {
        return Amp\call(function () {
            // Make sure an app token is available.
            $appToken = $this->getAppCredentials()->getAppToken();
            if ($appToken === null) {
                throw new Exception('Cannot create a Socket Mode connection without a configured app token');
            }

            // Prepare and send a request to the Slack API to get the Web Socket URL.
            $request = new Request('https://slack.com/api/apps.connections.open', 'POST');
            $request->addHeader('Content-Type', 'application/x-www-form-urlencoded');
            $request->addHeader('Authorization', "Bearer {$appToken}");
            /** @var Response $response */
            $response = yield $this->httpClient->request($request);
            if ($response->getStatus() !== 200) {
                throw new Exception('Request to get WSS URL failed');
            }

            // Extract the Websocket URL from the response from the Slack API.
            $contents = yield $response->getBody()->buffer();
            $result = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (!isset($result['ok'], $result['url']) || !$result['ok']) {
                throw new Exception('Response containing WSS URL was invalid');
            }
            $wssUrl = $result['url'];
            if ($this->debugReconnects) {
                $wssUrl .= '&debug_reconnects=true';
            }

            // Establish and return a Connection to the Websocket.
            return Websocket\Client\connect($wssUrl);
        });
    }
}
