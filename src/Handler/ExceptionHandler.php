<?php

/*
 * slim-exception (https://github.com/juliangut/slim-exception).
 * Slim HTTP exceptions and exception handling.
 *
 * @license BSD-3-Clause
 * @link https://github.com/juliangut/slim-exception
 * @author Julián Gutiérrez <juliangut@gmail.com>
 */

declare(strict_types=1);

namespace Jgut\Slim\Exception\Handler;

use Fig\Http\Message\StatusCodeInterface;
use Jgut\Slim\Exception\HttpException;
use Jgut\Slim\Exception\HttpExceptionFormatter;
use Jgut\Slim\Exception\HttpExceptionHandler;
use Negotiation\BaseAccept;
use Negotiation\Negotiator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Http\Response;

/**
 * HTTP exception handler.
 */
class ExceptionHandler implements HttpExceptionHandler
{
    /**
     * Content type negotiator.
     *
     * @var Negotiator
     */
    protected $negotiator;

    /**
     * Formatter list.
     *
     * @var HttpExceptionFormatter[]
     */
    protected $formatters = [];

    /**
     * AbstractHttpExceptionHandler constructor.
     *
     * @param Negotiator $negotiator
     */
    public function __construct(Negotiator $negotiator)
    {
        $this->negotiator = $negotiator;
    }

    /**
     * Add exception formatter.
     *
     * @param HttpExceptionFormatter $formatter
     * @param string|string[]|null   $contentTypes
     *
     * @throws \RuntimeException
     */
    public function addFormatter(HttpExceptionFormatter $formatter, $contentTypes = null)
    {
        if ($contentTypes === null) {
            $contentTypes = $formatter->getContentTypes();
        }

        if (!is_array($contentTypes)) {
            $contentTypes = [$contentTypes];
        }

        $contentTypes = array_filter(
            $contentTypes,
            function ($contentType): bool {
                return is_string($contentType);
            }
        );

        if (!count($contentTypes)) {
            throw new \RuntimeException(sprintf('No content type defined for %s formatter', get_class($formatter)));
        }

        foreach ($contentTypes as $contentType) {
            $this->formatters[$contentType] = $formatter;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function handleException(
        ServerRequestInterface $request,
        ResponseInterface $response,
        HttpException $exception
    ): ResponseInterface {
        $contentType = $this->getContentType($request);
        $outputContent = $this->getExceptionOutput($contentType, $exception, $request);

        return $this->getNewResponse($outputContent)
            ->withProtocolVersion($response->getProtocolVersion())
            ->withStatus($exception->getStatusCode())
            ->withHeader('Content-Type', $contentType . '; charset=utf-8');
    }

    /**
     * Get request content type.
     *
     * @param ServerRequestInterface $request
     *
     * @throws \RuntimeException
     *
     * @return string
     */
    protected function getContentType(ServerRequestInterface $request): string
    {
        if (count($this->formatters) === 0) {
            throw new \RuntimeException('No formatters defined');
        }

        $header = trim($request->getHeaderLine('Accept'));
        $priorities = array_keys($this->formatters);
        $contentType = $priorities[0];

        if ($header !== '') {
            try {
                /* @var BaseAccept $selected */
                $selected = $this->negotiator->getBest($header, $priorities);

                if ($selected instanceof BaseAccept) {
                    $contentType = $selected->getType();
                }
                // @codeCoverageIgnoreStart
            } catch (\Exception $exception) {
                // No action needed
            }
            // @codeCoverageIgnoreEnd
        }

        if (strpos($contentType, '/*+') !== false) {
            $contentType = str_replace('/*+', '/', $contentType);
        }

        return $contentType;
    }

    /**
     * Get error content.
     *
     * @param string                 $contentType
     * @param HttpException          $exception
     * @param ServerRequestInterface $request
     *
     * @return string
     */
    protected function getExceptionOutput(
        string $contentType,
        HttpException $exception,
        ServerRequestInterface $request
    ): string {
        return $this->formatters[$contentType]->formatException($exception, $request);
    }

    /**
     * Get new response object.
     *
     * @param string $content
     *
     * @return ResponseInterface
     */
    protected function getNewResponse(string $content): ResponseInterface
    {
        $response = new Response(StatusCodeInterface::STATUS_OK);
        $response->getBody()->write($content);

        return $response;
    }
}
