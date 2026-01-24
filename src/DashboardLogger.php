<?php

namespace BlaxSoftware\LaravelWebSockets;

use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;

class DashboardLogger
{
    const LOG_CHANNEL_PREFIX = 'private-websockets-dashboard-';

    const TYPE_DISCONNECTED = 'disconnected';
    const TYPE_CONNECTED = 'connected';
    const TYPE_SUBSCRIBED = 'subscribed';
    const TYPE_WS_MESSAGE = 'ws-message';
    const TYPE_API_MESSAGE = 'api-message';
    const TYPE_REPLICATOR_SUBSCRIBED = 'replicator-subscribed';
    const TYPE_REPLICATOR_UNSUBSCRIBED = 'replicator-unsubscribed';
    const TYPE_REPLICATOR_MESSAGE_RECEIVED = 'replicator-message-received';

    /**
     * The list of all channels.
     */
    public static array $channels = [
        self::TYPE_DISCONNECTED,
        self::TYPE_CONNECTED,
        self::TYPE_SUBSCRIBED,
        self::TYPE_WS_MESSAGE,
        self::TYPE_API_MESSAGE,
        self::TYPE_REPLICATOR_SUBSCRIBED,
        self::TYPE_REPLICATOR_UNSUBSCRIBED,
        self::TYPE_REPLICATOR_MESSAGE_RECEIVED,
    ];

    /**
     * Whether dashboard logging is enabled.
     * Cached to avoid repeated config lookups.
     */
    private static ?bool $enabled = null;

    /**
     * Cached channel manager instance.
     */
    private static ?ChannelManager $channelManager = null;

    /**
     * Log an event for an app.
     * Optimized: Early exit if disabled, cached config lookups.
     *
     * @param  mixed  $appId
     * @param  string  $type
     * @param  array  $details
     */
    public static function log($appId, string $type, array $details = []): void
    {
        // Cache enabled check
        if (self::$enabled === null) {
            self::$enabled = config('websockets.dashboard.enabled', true);
        }

        // Skip if dashboard is disabled
        if (!self::$enabled) {
            return;
        }

        // Cache channel manager
        if (self::$channelManager === null) {
            self::$channelManager = app(ChannelManager::class);
        }

        $channelName = static::LOG_CHANNEL_PREFIX . $type;

        // Build payload - use date() instead of deprecated strftime()
        $payload = (object) [
            'event' => 'log-message',
            'channel' => $channelName,
            'data' => [
                'type' => $type,
                'time' => date('H:i:s'),
                'details' => $details,
            ],
        ];

        // Check if channel exists locally and broadcast
        $channel = self::$channelManager->find($appId, $channelName);
        if ($channel) {
            $channel->broadcastLocally($appId, $payload);
        }

        // Always broadcast across servers (preserving original behavior)
        // The channel manager handles the replication logic
        self::$channelManager->broadcastAcrossServers(
            $appId,
            null,
            $channelName,
            $payload
        );
    }

    /**
     * Reset cached state (useful for testing)
     */
    public static function reset(): void
    {
        self::$enabled = null;
        self::$channelManager = null;
    }
}
