<?php

namespace BlaxSoftware\LaravelWebSockets\Server\Messages;

use BlaxSoftware\LaravelWebSockets\Events\ConnectionPonged;
use Ratchet\ConnectionInterface;
use stdClass;

class PusherChannelProtocolMessage extends PusherClientMessage
{
    /**
     * Pre-encoded pong response for performance
     */
    private const PONG_RESPONSE = '{"event":"pusher.pong"}';

    /**
     * Respond with the payload.
     * Optimized: Uses direct method dispatch instead of reflection.
     */
    public function respond(): void
    {
        $event = $this->payload->event ?? '';

        // Fast path for ping - most common pusher protocol message
        if ($event === 'pusher:ping' || $event === 'pusher.ping') {
            $this->pingFast($this->connection);
            return;
        }

        // Extract method name from event (e.g., 'pusher:subscribe' -> 'subscribe')
        $colonPos = strpos($event, ':');
        if ($colonPos !== false) {
            $eventName = substr($event, $colonPos + 1);
        } else {
            $dotPos = strpos($event, '.');
            $eventName = $dotPos !== false ? substr($event, $dotPos + 1) : '';
        }

        // Convert to camelCase if needed (e.g., 'channel-name' -> 'channelName')
        if (strpos($eventName, '-') !== false) {
            $eventName = lcfirst(str_replace('-', '', ucwords($eventName, '-')));
        }

        if ($eventName && $eventName !== 'respond' && method_exists($this, $eventName)) {
            $this->$eventName($this->connection, $this->payload->data ?? new stdClass());
        }
    }

    /**
     * Fast ping handler - avoids promise chain and event dispatch
     */
    protected function pingFast(ConnectionInterface $connection): void
    {
        // Update timestamp directly on connection (no promise chain)
        $connection->lastPongedAt = time();

        // Send pre-encoded response (no json_encode overhead)
        $connection->send(self::PONG_RESPONSE);

        // Skip event dispatch for ping - it's high frequency and events are expensive
        // If you need ping events, use: ConnectionPonged::dispatch($connection->app->id, $connection->socketId);
    }

    /**
     * Legacy ping handler - kept for compatibility
     * @deprecated Use pingFast instead
     */
    protected function ping(ConnectionInterface $connection): void
    {
        $this->pingFast($connection);
    }

    /**
     * Subscribe to channel.
     *
     * @see    https://pusher.com/docs/pusher_protocol#pusher-subscribe
     */
    protected function subscribe(ConnectionInterface $connection, stdClass $payload): void
    {
        $this->channelManager->subscribeToChannel($connection, $payload->channel, $payload);
    }

    /**
     * Unsubscribe from the channel.
     */
    public function unsubscribe(ConnectionInterface $connection, stdClass $payload): void
    {
        $this->channelManager->unsubscribeFromChannel($connection, $payload->channel, $payload);
    }
}
