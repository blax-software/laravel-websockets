<?php

namespace BlaxSoftware\LaravelWebSockets\Server\Messages;

use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;
use BlaxSoftware\LaravelWebSockets\Contracts\PusherMessage;
use Illuminate\Support\Str;
use Ratchet\ConnectionInterface;
use Ratchet\RFC6455\Messaging\MessageInterface;

class PusherMessageFactory
{
    /**
     * Create a new message.
     *
     * @param  \Ratchet\RFC6455\Messaging\MessageInterface  $message
     * @param  \Ratchet\ConnectionInterface  $connection
     * @param  \BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager  $channelManager
     * @return PusherMessage
     */
    public static function createForMessage(
        MessageInterface $message,
        ConnectionInterface $connection,
        ChannelManager $channelManager): PusherMessage
    {
        $payload = json_decode($message->getPayload());

        return Str::startsWith($payload->event, 'pusher:')
            ? new PusherChannelProtocolMessage($payload, $connection, $channelManager)
            : new PusherClientMessage($payload, $connection, $channelManager);
    }
}
