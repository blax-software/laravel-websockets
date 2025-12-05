<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Broadcasting Routes
|--------------------------------------------------------------------------
|
| Here are the default broadcasting routes for Laravel WebSockets.
| Users can override these by defining their own routes with the same names.
|
*/

Route::any('broadcasting/auth', function () {
    if (!request()->has('socket_id')) {
        return response()->json(['error' => 'Socket ID is required'], 400);
    }

    if (!request()->has('channel_name')) {
        return response()->json(['error' => 'Channel name is required'], 400);
    }

    try {
        $auth = \Illuminate\Support\Facades\Broadcast::auth(request());
    } catch (\Throwable $e) {
        $auth = [];
    }

    $auth['socket_id'] = request()->get('socket_id');

    config(['cache.default' => 'file']);
    cache()->remember('socket_' . request()->get('socket_id'), 15, function () {
        return [
            'id' => optional(auth())->id(),
            'type' => optional(auth())->user()
                ? get_class(auth()->guard()->user())
                : null,
        ];
    });

    return @$auth;
});
