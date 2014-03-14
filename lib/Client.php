<?php

namespace Artax;

use Alert\ReactorFactory, Alert\Reactor, Alert\Future;

class Client implements Observable {
    private $reactor;
    private $asyncClient;
    private $pendingMultiRequests;
    private $onEachMultiResult;

    /**
     * These constructor parameters allow for lazy injection and improved testability.
     * Unless you *really* know what you're doing you shouldn't specify your own
     * arguments when instantiating Artax\Client objects. If you do pass in your own
     * dependencies it's critical that the Artax\AsyncClient instance uses the same
     * Alert\Reactor instance passed to parameter 1 of this constructor.
     *
     * @param \Alert\Reactor $reactor
     * @param \Artax\AsyncClient $asyncClient
     */
    public function __construct(Reactor $reactor = NULL, AsyncClient $asyncClient = NULL) {
        $this->reactor = $reactor ?: (new ReactorFactory)->select();
        $this->asyncClient = $asyncClient ?: new AsyncClient($this->reactor);
    }

    /**
     * Synchronously request an HTTP resource
     *
     * @param $uriOrRequest string|Request An HTTP(s) URI or Artax\Request instance
     * @throws \Artax\ClientException On socket-level connection issues
     * @return \Artax\Response A mutable object modeling the raw HTTP response
     */
    public function request($uriOrRequest) {
        $response = NULL;
        $future = $this->asyncClient->request($uriOrRequest);
        $future->onComplete(function(Future $f) use (&$response) {
            $response = $f->getValue();
        });

        while (empty($response)) {
            $this->reactor->tick();
        }

        return $response;
    }

    /**
     * Synchronously request multiple HTTP resources in parallel
     *
     * Note that though the individual requests in the batch are retrieved in parallel, the function
     * call itself will not return until all requests in the group have completed.
     *
     * @param array $requests An array of URI strings and/or Artax\Request instances
     * @return array[Alert\Future] Returns an array of response futures
     */
    public function requestMulti(array $requests, callable $onEachMultiResult) {
        if (empty($requests)) {
            throw new \InvalidArgumentException(
                'Request array must not be empty'
            );
        }

        $this->pendingMultiRequests = [];
        $this->onEachMultiResult = $onEachMultiResult;
        foreach ($requests as $requestKey => $requestOrUri) {
            $this->scheduleMultiRequest($requestKey, $requestOrUri);
        }

        while ($this->pendingMultiRequests) {
            $this->reactor->tick();
        }

        $this->onEachMultiResult = NULL;
    }

    private function scheduleMultiRequest($requestKey, $requestOrUri) {
        $this->reactor->immediately(function() use ($requestKey, $requestOrUri) {
            $future = $this->asyncClient->request($requestOrUri);
            $future->onComplete(function(Future $f) use ($requestKey) {
                $this->onMultiResult($f, $requestKey);
            });

            $this->pendingMultiRequests[$requestKey] = $requestOrUri;
        });
    }

    private function onMultiResult(Future $f, $requestKey) {
        $requestOrUri = $this->pendingMultiRequests[$requestKey];
        unset($this->pendingMultiRequests[$requestKey]);

        if ($f->succeeded()) {
            $response = $f->getValue();
            $error = NULL;
        } else {
            $response = NULL;
            $error = $f->getError();
        }

        call_user_func($this->onEachMultiResult, $response, $error, $requestKey, $requestOrUri);
    }

    /**
     * Assign multiple client options from a key-value array
     *
     * @param array $options An array matching option name keys to option values
     * @throws \DomainException On unknown option key
     * @return \Artax\Client Returns the current object instance
     */
    public function setAllOptions(array $options) {
        $this->asyncClient->setAllOptions($options);
        
        return $this;
    }

    /**
     * Assign a client option
     *
     * @param string $option
     * @param mixed $value Option value
     * @throws \DomainException On unknown option key
     * @return \Artax\Client Returns the current object instance
     */
    public function setOption($option, $value) {
        $this->asyncClient->setOption($option, $value);
        
        return $this;
    }

    /**
     * Attach an array of event observations
     *
     * @param array $eventListenerMap
     *
     * @internal param array $listeners A key-value array mapping event names to callable listeners
     * @return \Artax\Observation
     */
    public function addObservation(array $eventListenerMap) {
        return $this->asyncClient->addObservation($eventListenerMap);
    }

    /**
     * Cancel the specified Observation
     *
     * @param Observation $observation
     * @return void
     */
    public function removeObservation(Observation $observation) {
        $this->asyncClient->removeObservation($observation);
    }

    /**
     * Clear all existing observations of this Observable
     *
     * @return void
     */
    public function removeAllObservations() {
        $this->asyncClient->removeAllObservations();
    }

    /**
     * Cancel a specific outstanding request
     *
     * @param \Artax\Request $request
     * @return void
     */
    public function cancel(Request $request) {
        $this->asyncClient->cancel($request);
        $this->clearPendingMultiRequest($request);
    }

    /**
     * Cancel all outstanding requests
     *
     * @return void
     */
    public function cancelAll() {
        $this->asyncClient->cancelAll();
        $this->pendingMultiRequests = new \SplObjectStorage;
        $this->reactor->stop();
    }

}
