<?php

namespace Guzzle\Http;

use Guzzle\Common\Event;
use Guzzle\Http\Exception\BadResponseException;
use Guzzle\Http\Message\RedirectHistory;
use Guzzle\Http\Url;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Exception\TooManyRedirectsException;
use Guzzle\Http\Exception\CouldNotRewindStreamException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin to implement HTTP redirects. Can redirect like a web browser or using strict RFC 2616 compliance
 */
class RedirectPlugin implements EventSubscriberInterface
{
    const REDIRECT_COUNT = 'redirect.count';
    const MAX_REDIRECTS = 'redirect.max';
    const STRICT_REDIRECTS = 'redirect.strict';
    const REDIRECT_HISTORY = 'redirect.history';
    const PARENT_REQUEST = 'redirect.parent_request';
    const DISABLE = 'redirect.disable';

    /**
     * @var int Default number of redirects allowed when no setting is supplied by a request
     */
    protected $defaultMaxRedirects = 5;

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            'request.sent'        => array('onRequestSent', 100),
            'request.clone'       => 'onRequestClone',
            'request.before_send' => 'onRequestClone'
        );
    }

    /**
     * Clean up the parameters of a request when it is cloned
     *
     * @param Event $event Event emitted
     */
    public function onRequestClone(Event $event)
    {
        $event['request']->getParams()
            ->remove(self::REDIRECT_COUNT)->remove(self::PARENT_REQUEST)->remove(self::REDIRECT_HISTORY);
    }

    /**
     * Called when a request receives a redirect response
     *
     * @param Event $event Event emitted
     */
    public function onRequestSent(Event $event)
    {
        $response = $event['response'];
        $request = $event['request'];

        // Only act on redirect requests with Location headers
        if (!$response || $request->getParams()->get(self::DISABLE)) {
            return;
        }

        // Trace the original request based on parameter history
        $original = $this->getOriginalRequest($request);

        // Terminating condition to set the effective repsonse on the original request
        if (!$response->isRedirect() || !$response->hasHeader('Location')) {
            if ($request !== $original) {
                // This is a terminating redirect response, so set it on the original request
                $response->setRedirectHistory($original->getParams()->get('redirect.history'));
                $original->setResponse($response);
            }
            return;
        }

        $this->sendRedirectRequest($original, $request, $response);
    }

    /**
     * Get the original request that initiated a series of redirects
     *
     * @param RequestInterface $request Request to get the original request from
     *
     * @return RequestInterface
     */
    protected function getOriginalRequest(RequestInterface $request)
    {
        $original = $request;
        // The number of redirects is held on the original request, so determine which request that is
        while ($parent = $original->getParams()->get(self::PARENT_REQUEST)) {
            $original = $parent;
        }

        return $original;
    }

    /**
     * Create a redirect request for a specific request object
     *
     * Takes into account strict RFC compliant redirection (e.g. redirect POST with POST) vs doing what most clients do
     * (e.g. redirect POST with GET).
     *
     * @param RequestInterface $request    Request being redirected
     * @param RequestInterface $original   Original request
     * @param int              $statusCode Status code of the redirect
     * @param string           $location   Location header of the redirect
     *
     * @return RequestInterface Returns a new redirect request
     * @throws CouldNotRewindStreamException If the body needs to be rewound but cannot
     */
    protected function createRedirectRequest(
        RequestInterface $request,
        $statusCode,
        $location,
        RequestInterface $original
    ) {
        $redirectRequest = null;
        $strict = $original->getParams()->get(self::STRICT_REDIRECTS);

        // Use a GET request if this is an entity enclosing request and we are not forcing RFC compliance, but rather
        // emulating what all browsers would do
        if ($request instanceof EntityEnclosingRequestInterface && !$strict && $statusCode <= 302) {
            $redirectRequest = RequestFactory::getInstance()->cloneRequestWithMethod($request, 'GET');
        } else {
            $redirectRequest = clone $request;
        }

        $redirectRequest->setIsRedirect(true);
        // Always use the same response body when redirecting
        $redirectRequest->setResponseBody($request->getResponseBody());

        $location = Url::factory($location);
        // If the location is not absolute, then combine it with the original URL
        if (!$location->isAbsolute()) {
            $originalUrl = $redirectRequest->getUrl(true);
            // Remove query string parameters and just take what is present on the redirect Location header
            $originalUrl->getQuery()->clear();
            $location = $originalUrl->combine((string) $location);
        }

        $redirectRequest->setUrl($location);

        // Add the parent request to the request before it sends (make sure it's before the onRequestClone event too)
        $redirectRequest->getEventDispatcher()->addListener('request.before_send', function ($e) use ($request) {
            $e['request']->getParams()->set(RedirectPlugin::PARENT_REQUEST, $request);
        }, -1);

        // Rewind the entity body of the request if needed
        if ($redirectRequest instanceof EntityEnclosingRequestInterface && $redirectRequest->getBody()) {
            $body = $redirectRequest->getBody();
            // Only rewind the body if some of it has been read already, and throw an exception if the rewind fails
            if ($body->ftell() && !$body->rewind()) {
                throw new CouldNotRewindStreamException(
                    'Unable to rewind the non-seekable entity body of the request after redirecting. cURL probably '
                    . 'sent part of body before the redirect occurred. Try adding acustom rewind function using on the '
                    . 'entity body of the request using setRewindFunction().'
                );
            }
        }

        return $redirectRequest;
    }

    /**
     * Prepare the request for redirection and enforce the maximum number of allowed redirects per client
     *
     * @param RequestInterface $original  Origina request
     * @param RequestInterface $request   Request to prepare and validate
     * @param Response         $response  The current response
     *
     * @return RequestInterface
     */
    protected function prepareRedirection(RequestInterface $original, RequestInterface $request, Response $response)
    {
        $params = $original->getParams();
        // This is a new redirect, so increment the redirect counter
        $current = $params->get(self::REDIRECT_COUNT) + 1;
        $params->set(self::REDIRECT_COUNT, $current);

        // Use a provided maximum value or default to a max redirect count of 5
        $max = $params->hasKey(self::MAX_REDIRECTS)
            ? $params->get(self::MAX_REDIRECTS)
            : $this->defaultMaxRedirects;

        // Throw an exception if the redirect count is exceeded
        if ($current > $max) {
            $this->throwTooManyRedirectsException($original, $request);
            return false;
        } else {
            // Create a redirect request based on the redirect rules set on the request
            return $this->createRedirectRequest(
                $request,
                $response->getStatusCode(),
                trim($response->getHeader('Location')),
                $original
            );
        }
    }

    /**
     * Send a redirect request and handle any errors
     *
     * @param RequestInterface $original The originating request
     * @param RequestInterface $request  The current request being redirected
     * @param Response         $response The response of the current request
     *
     * @throws BadResponseException|\Exception
     */
    protected function sendRedirectRequest(RequestInterface $original, RequestInterface $request, Response $response)
    {
        // Validate and create a redirect request based on the original request and current response
        if (!$redirectRequest = $this->prepareRedirection($original, $request, $response)) {
            return;
        }

        // Keep a redirect history on the original request
        if (!$history = $original->getParams()->get('redirect.history')) {
            $history = new RedirectHistory();
            $history->addTransaction($original, $original->getResponse());
            $original->getParams()->set('redirect.history', $history);
        }

        // Add to the transaction history before we get a response for the correct order and in case of failure
        $currentTransaction = $history->addTransaction($redirectRequest);

        try {
            $redirectResponse = $redirectRequest->send();
        } catch (BadResponseException $e) {
            $redirectResponse = $e->getResponse();
            if (!$e->getResponse()) {
                throw $e;
            }
        }

        // Update the history
        $history->setTransactionResponse($currentTransaction, $redirectResponse);
    }

    /**
     * Throw a too many redirects exception for a request
     *
     * @param RequestInterface $original Request
     * @param RequestInterface $request Request
     *
     * @throws TooManyRedirectsException when too many redirects have been issued
     */
    protected function throwTooManyRedirectsException(RequestInterface $original, RequestInterface $request)
    {
        $history = $original->getParams()->get('redirect.history');
        $request->getResponse()->setRedirectHistory($history);
        $original->getEventDispatcher()->addListener('request.complete', function ($e) use ($history) {
            $e['request']->getResponse()->setRedirectHistory($history);
            throw new TooManyRedirectsException("Too many redirects were issued for this transaction:\n{$history}");
        });
    }
}
