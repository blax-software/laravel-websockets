<?php

// Polyfill for GuzzleHttp\Psr7\uri_for() which was removed in guzzlehttp/psr7 2.x
// Required by ratchet/pawl 0.3.x - must be defined before autoload
namespace GuzzleHttp\Psr7 {
    if (!function_exists('GuzzleHttp\Psr7\uri_for')) {
        /**
         * Returns a UriInterface for the given value.
         *
         * @param string|\Psr\Http\Message\UriInterface $uri
         * @return \Psr\Http\Message\UriInterface
         */
        function uri_for($uri)
        {
            if ($uri instanceof \Psr\Http\Message\UriInterface) {
                return $uri;
            }

            if (is_string($uri)) {
                return new Uri($uri);
            }

            throw new \InvalidArgumentException('URI must be a string or UriInterface');
        }
    }
}

namespace {
    // Suppress deprecation warnings from vendor packages during class loading
    error_reporting(E_ALL & ~E_DEPRECATED);

    // Set a custom error handler to filter out deprecation warnings from vendor packages
    set_error_handler(function ($severity, $message, $file, $line) {
        if ($severity === E_DEPRECATED && strpos($file, '/vendor/') !== false) {
            return true;
        }
        return false;
    }, E_DEPRECATED);

    require dirname(__DIR__) . '/vendor/autoload.php';
}
