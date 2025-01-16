<?php

namespace BlaxSoftware\LaravelWebSockets\Contracts;

use BlaxSoftware\LaravelWebSockets\Apps\App;
use React\Promise\PromiseInterface;

interface AppManager
{
    /**
     * Get all apps.
     *
     * @return PromiseInterface
     */
    public function all(): PromiseInterface;

    /**
     * Get app by id.
     *
     * @param  string|int  $appId
     * @return PromiseInterface
     */
    public function findById($appId): PromiseInterface;

    /**
     * Get app by app key.
     *
     * @param  string  $appKey
     * @return PromiseInterface
     */
    public function findByKey($appKey): PromiseInterface;

    /**
     * Get app by secret.
     *
     * @param  string  $appSecret
     * @return PromiseInterface
     */
    public function findBySecret($appSecret): PromiseInterface;

    /**
     * Create a new app.
     *
     * @param  $appData
     * @return PromiseInterface
     */
    public function createApp($appData): PromiseInterface;
}
