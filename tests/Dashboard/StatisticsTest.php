<?php

namespace BlaxSoftware\LaravelWebSockets\Test\Dashboard;

use BlaxSoftware\LaravelWebSockets\Test\Models\User;
use BlaxSoftware\LaravelWebSockets\Test\TestCase;

class StatisticsTest extends TestCase
{
    public function test_can_get_statistics()
    {
        $rick = $this->newActiveConnection(['public-channel']);
        $morty = $this->newActiveConnection(['public-channel']);

        $this->statisticsCollector->save();

        $response = $this->actingAs(factory(User::class)->create())
            ->json('GET', route('laravel-websockets.statistics', ['appId' => '1234']))
            ->assertResponseOk()
            ->seeJsonStructure([
                'peak_connections' => ['x', 'y'],
                'websocket_messages_count' => ['x', 'y'],
                'api_messages_count' => ['x', 'y'],
            ]);
    }

    public function test_cant_get_statistics_for_invalid_app_id()
    {
        $rick = $this->newActiveConnection(['public-channel']);
        $morty = $this->newActiveConnection(['public-channel']);

        $this->statisticsCollector->save();

        $this->actingAs(factory(User::class)->create())
            ->json('GET', route('laravel-websockets.statistics', ['appId' => 'not_found']))
            ->seeJson([
                'peak_connections' => ['x' => [], 'y' => []],
                'websocket_messages_count' => ['x' => [], 'y' => []],
                'api_messages_count' => ['x' => [], 'y' => []],
            ]);
    }
}
