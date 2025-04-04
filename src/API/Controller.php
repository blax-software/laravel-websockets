<?php

namespace BlaxSoftware\LaravelWebSockets\API;

use BlaxSoftware\LaravelWebSockets\Apps\App;
use BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager;
use BlaxSoftware\LaravelWebSockets\Server\QueryParameters;
use Exception;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Psr\Http\Message\RequestInterface;
use Pusher\Pusher;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class Controller implements HttpServerInterface
{
    /**
     * The request buffer.
     *
     * @var string
     */
    protected $requestBuffer = '';

    /**
     * The incoming request.
     *
     * @var \Psr\Http\Message\RequestInterface
     */
    protected $request;

    /**
     * The content length that will
     * be calculated.
     *
     * @var int
     */
    protected $contentLength;

    /**
     * The channel manager.
     *
     * @var \BlaxSoftware\LaravelWebSockets\Contracts\ChannelManager
     */
    protected $channelManager;

    /**
     * Initialize the request.
     *
     * @param  ChannelManager  $channelManager
     * @return void
     */
    public function __construct(ChannelManager $channelManager)
    {
        $this->channelManager = $channelManager;
    }

    /**
     * Handle the opened socket connection.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @param  \Psr\Http\Message\RequestInterface  $request
     * @return void
     */
    public function onOpen(ConnectionInterface $connection, RequestInterface $request = null)
    {
        $this->request = $request;

        $this->contentLength = $this->findContentLength($request->getHeaders());

        $this->requestBuffer = (string) $request->getBody();

        if (! $this->verifyContentLength()) {
            return;
        }

        $this->handleRequest($connection);
    }

    /**
     * Handle the oncoming message and add it to buffer.
     *
     * @param  \Ratchet\ConnectionInterface  $from
     * @param  mixed  $msg
     * @return void
     */
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $this->requestBuffer .= $msg;

        if (! $this->verifyContentLength()) {
            return;
        }

        $this->handleRequest($from);
    }

    /**
     * Handle the socket closing.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @return void
     */
    public function onClose(ConnectionInterface $connection)
    {
        //
    }

    /**
     * Handle the errors.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @param  Exception  $exception
     * @return void
     */
    public function onError(ConnectionInterface $connection, Exception $exception)
    {
        if (! $exception instanceof HttpException) {
            return;
        }

        $response = new Response($exception->getStatusCode(), [
            'Content-Type' => 'application/json',
        ], json_encode([
            'error' => $exception->getMessage(),
        ]));

        tap($connection)->send(Message::toString($response))->close();
    }

    /**
     * Get the content length from the headers.
     *
     * @param  array  $headers
     * @return int
     */
    protected function findContentLength(array $headers): int
    {
        return Collection::make($headers)->first(function ($values, $header) {
            return strtolower($header) === 'content-length';
        })[0] ?? 0;
    }

    /**
     * Check the content length.
     *
     * @return bool
     */
    protected function verifyContentLength()
    {
        return strlen($this->requestBuffer) === $this->contentLength;
    }

    /**
     * Handle the oncoming connection.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @return void
     */
    protected function handleRequest(ConnectionInterface $connection)
    {
        $serverRequest = (new ServerRequest(
            $this->request->getMethod(),
            $this->request->getUri(),
            $this->request->getHeaders(),
            $this->requestBuffer,
            $this->request->getProtocolVersion()
        ))->withQueryParams(QueryParameters::create($this->request)->all());

        $laravelRequest = Request::createFromBase((new HttpFoundationFactory)->createRequest($serverRequest));

        $this
            ->ensureValidAppId($laravelRequest->appId)
            ->then(function ($app) use ($laravelRequest, $connection) {
                try {
                    $this->ensureValidSignature($app, $laravelRequest);
                } catch (HttpException $exception) {
                    $this->onError($connection, $exception);

                    return;
                }

                // Invoke the controller action
                try {
                    $response = $this($laravelRequest);
                } catch (HttpException $exception) {
                    $this->onError($connection, $exception);

                    return;
                }

                // Allow for async IO in the controller action
                if ($response instanceof PromiseInterface) {
                    $response->then(function ($response) use ($connection) {
                        $this->sendAndClose($connection, $response);
                    });

                    return;
                }

                if ($response instanceof HttpException) {
                    $this->onError($connection, $response);

                    return;
                }

                $this->sendAndClose($connection, $response);
            });
    }

    /**
     * Send the response and close the connection.
     *
     * @param  \Ratchet\ConnectionInterface  $connection
     * @param  mixed  $response
     * @return void
     */
    protected function sendAndClose(ConnectionInterface $connection, $response)
    {
        tap($connection)->send(new JsonResponse($response))->close();
    }

    /**
     * Ensure app existence.
     *
     * @param  mixed  $appId
     * @return PromiseInterface
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function ensureValidAppId($appId)
    {
        $deferred = new Deferred();

        App::findById($appId)
            ->then(function ($app) use ($appId, $deferred) {
                if (! $app) {
                    throw new HttpException(401, "Unknown app id `{$appId}` provided.");
                }
                $deferred->resolve($app);
            });

        return $deferred->promise();
    }

    /**
     * Ensure signature integrity coming from an
     * authorized application.
     *
     * @param  App  $app
     * @param  Request  $request
     * @return $this
     */
    protected function ensureValidSignature(App $app, Request $request)
    {
        // The `auth_signature` & `body_md5` parameters are not included when calculating the `auth_signature` value.
        // The `appId`, `appKey` & `channelName` parameters are actually route parameters and are never supplied by the client.

        $params = Arr::except($request->query(), [
            'auth_signature', 'body_md5', 'appId', 'appKey', 'channelName',
        ]);

        if ($request->getContent() !== '') {
            $params['body_md5'] = md5($request->getContent());
        }

        ksort($params);

        $signature = "{$request->getMethod()}\n/{$request->path()}\n".Pusher::array_implode('=', '&', $params);

        $authSignature = hash_hmac('sha256', $signature, $app->secret);

        if ($authSignature !== $request->get('auth_signature')) {
            throw new HttpException(401, 'Invalid auth signature provided.');
        }

        return $this;
    }

    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    abstract public function __invoke(Request $request);
}
