<?php

namespace BlaxSoftware\LaravelWebSockets\Server\Messages;

use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;
use BlaxSoftware\LaravelWebSockets\Contracts\PusherMessage;
use Ratchet\ConnectionInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;

class PusherMessageFactory
{
    /**
     * Create a new message.
     * Optimized: Uses direct string comparison instead of Str::startsWith.
     *
     * @param  \Ratchet\RFC6455\Messaging\MessageInterface  $message
     * @param  \Ratchet\ConnectionInterface  $connection
     * @param  \BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager  $channelManager
     * @return PusherMessage
     */
    public static function createForMessage(
        MessageInterface $message,
        ConnectionInterface $connection,
        ChannelManager $channelManager
    ): PusherMessage {
        $payload = json_decode($message->getPayload());
        $event = $payload->event ?? '';

        // Fast string prefix check (faster than Str::startsWith)
        // Check first 7 chars for 'pusher.' or 'pusher:'
        $isPusherEvent = (
            isset($event[6]) &&
            $event[0] === 'p' &&
            $event[1] === 'u' &&
            $event[2] === 's' &&
            $event[3] === 'h' &&
            $event[4] === 'e' &&
            $event[5] === 'r' &&
            ($event[6] === '.' || $event[6] === ':')
        );

        return $isPusherEvent
            ? new PusherChannelProtocolMessage($payload, $connection, $channelManager)
            : new PusherClientMessage($payload, $connection, $channelManager);
    }
}
