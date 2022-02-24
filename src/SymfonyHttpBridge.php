<?php

namespace Runtime\Swoole;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bridge between Symfony and Swoole Http API.
 *
 * @author Piotr Kugla <piku235@gmail.com>
 *
 * @internal
 */
final class SymfonyHttpBridge
{
    public static function convertSwooleRequest(Request $request): SymfonyRequest
    {
        $sfRequest = new SymfonyRequest(
            $request->get ?? [],
            $request->post ?? [],
            [],
            $request->cookie ?? [],
            $request->files ?? [],
            array_change_key_case($request->server ?? [], CASE_UPPER),
            $request->rawContent()
        );
        $sfRequest->headers = new HeaderBag($request->header ?? []);

        return $sfRequest;
    }

    public static function reflectSymfonyResponse(SymfonyResponse $sfResponse, Response $response): void
    {
        foreach ($sfResponse->headers->all() as $name => $values) {
            $response->header((string) $name, $values);
        }

        $response->status($sfResponse->getStatusCode());

        switch (true) {
            case $sfResponse instanceof BinaryFileResponse && $sfResponse->headers->has('Content-Range'):
            case $sfResponse instanceof StreamedResponse:
                // register an outer ob_handler that will always swallow all messages that would
                // be send to stdout otherwise, when the other ob_handler throws an exception
                ob_start(function () {
                    return '';
                });

                ob_start(function ($buffer) use ($response) {
                    if(empty($buffer))
                        return;

                    if(!$response->isWritable() || $response->write($buffer) === false)
                        throw new StreamClosedException("Client closed the stream");

                    return '';
                }, 4096);


                try {
                    $sfResponse->sendContent();
                } catch(StreamClosedException $e) {
                    // swallow StreamClosedException if it was not handled by the callback
                }

                ob_end_clean(); // closes inner ob_handler
                ob_end_clean(); // closes outer ob_handler

                if($response->isWritable())
                    $response->end();
                break;
            case $sfResponse instanceof BinaryFileResponse:
                $response->sendfile($sfResponse->getFile()->getPathname());
                break;
            default:
                $response->end($sfResponse->getContent());
        }
    }
}
