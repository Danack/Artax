<?php

namespace Artax;

use Alert\Reactor,
    Alert\Promise,
    Artax\Entity\ResourceBody,
    Artax\Entity\BodyAggregate,
    Artax\Entity\ChunkingIterator,
    Artax\Conn\Socket,
    Artax\Conn\DnsException,
    Artax\Conn\SocketFactory;

class AsyncClient implements Observable  {
    use ObservableSubject;

    private $reactor;
    private $sockets;
    private $cycles;
    private $requestQueue;
    private $hasExtZlib;
    private $autoEncoding = TRUE;
    private $useKeepAlive = TRUE;
    private $connectTimeout = 15;
    private $transferTimeout = 30;
    private $keepAliveTimeout = 30;
    private $followLocation = TRUE;
    private $autoReferer = TRUE;
    private $maxConnections = -1;
    private $maxConnectionsPerHost = 8;
    private $maxHeaderBytes = -1;
    private $maxBodyBytes = -1;
    private $bodySwapSize = 2097152;
    private $storeBody = TRUE;
    private $bufferBody = TRUE;
    private $bindToIp;
    private $continueDelay = 3;
    private $expectContinue = TRUE;
    private $ioGranularity = 65536;
    private $verboseRead = FALSE;
    private $verboseSend = FALSE;
    private $userAgentString = '';
    private $tlsOptions = [
        'verify_peer' => TRUE,
        'allow_self_signed' => NULL,
        'cafile' => NULL,
        'capath' => NULL,
        'local_cert' => NULL,
        'passphrase' => NULL,
        'CN_match' => NULL,
        'verify_depth' => NULL,
        'ciphers' => NULL,
        'SNI_enabled' => NULL,
        'SNI_server_name' => NULL
    ];

    public function __construct(Reactor $reactor) {
        $this->reactor = $reactor;
        $this->sockets = new \SplObjectStorage;
        $this->cycles = new \SplObjectStorage;
        $this->requestQueue = new \SplObjectStorage;
        $this->tlsOptions['cafile'] = dirname(dirname(__DIR__)) . '/certs/cacert.pem';
        $this->hasExtZlib = extension_loaded('zlib');
    }

    /**
     * Asynchronously request an HTTP resource
     *
     * @param mixed $uriOrRequest An HTTP URI string or Artax\Request instance
     * @return \Alert\Future
     */
    public function request($uriOrRequest) {
        $request = $this->normalizeRequest($uriOrRequest);
        $promise = new Promise;
        $cycle = new Cycle;
        $cycle->promise = $promise;
        $cycle->request = $request;
        $cycle->authority = $this->generateAuthorityFromUri($request->getUri());
        $this->requestQueue->attach($request, $cycle);
        $this->notifyObservations(Event::REQUEST, [$request, NULL]);
        if ($this->resolveDns($cycle)) {
            $this->assignRequestSockets();
        }

        return $promise->getFuture();
    }

    /**
     * Cancel a specific outstanding request
     *
     * @param \Artax\Request $request
     * @return \Artax\AsyncClient Returns the current object instance
     */
    public function cancel(Request $request) {
        if ($this->requestQueue->contains($request)) {
            $this->requestQueue->detach($request);
            $this->notifyObservations(Event::CANCEL, [$request, NULL]);
        } elseif ($this->cycles->contains($request)) {
            $cycle = $this->cycles->offsetGet($request);
            $this->checkinSocket($cycle);
            $this->clearSocket($cycle);
            $this->endRequestSubscriptions($cycle);
            $this->cycles->detach($request);
            $this->notifyObservations(Event::CANCEL, [$request, NULL]);
        }

        return $this;
    }

    /**
     * Cancel all outstanding requests
     *
     * @return \Artax\AsyncClient Returns the current object instance
     */
    public function cancelAll() {
        foreach ($this->cycles as $request) {
            $this->cancel($request);
        }
        foreach ($this->requestQueue as $request) {
            $this->cancel($request);
        }

        return $this;
    }

    /**
     * Assign multiple client options from a key-value array
     *
     * @param array $options An array matching option name keys to option values
     * @throws \DomainException On unknown option key
     * @return \Artax\AsyncClient Returns the current object instance
     */
    public function setAllOptions(array $options) {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }

        return $this;
    }

    /**
     * Assign a client option
     *
     * @param int $option Artax option identifier
     * @param mixed $value Option value
     * @throws \DomainException On unknown option key
     * @return \Artax\AsyncClient Returns the current object instance
     */
    public function setOption($option, $value) {
        switch ($option) {
            case Options::USE_KEEP_ALIVE:
                $this->setUseKeepAlive($value);
                break;
            case Options::CONNECT_TIMEOUT:
                $this->setConnectTimeout($value);
                break;
            case Options::TRANSFER_TIMEOUT:
                $this->setTransferTimeout($value);
                break;
            case Options::KEEP_ALIVE_TIMEOUT:
                $this->setKeepAliveTimeout($value);
                break;
            case Options::FOLLOW_LOCATION:
                $this->setFollowLocation($value);
                break;
            case Options::AUTO_REFERER:
                $this->setAutoReferer($value);
                break;
            case Options::MAX_CONNECTIONS:
                $this->setMaxConnections($value);
                break;
            case Options::MAX_CONNECTIONS_PER_HOST:
                $this->setMaxConnectionsPerHost($value);
                break;
            case Options::CONTINUE_DELAY:
                $this->setContinueDelay($value);
                break;
            case Options::BUFFER_BODY:
                $this->setBufferBody($value);
                break;
            case Options::MAX_HEADER_BYTES:
                $this->setMaxHeaderBytes($value);
                break;
            case Options::MAX_BODY_BYTES:
                $this->setMaxBodyBytes($value);
                break;
            case Options::BODY_SWAP_SIZE:
                $this->setBodySwapSize($value);
                break;
            case Options::STORE_BODY:
                $this->setStoreBody($value);
                break;
            case Options::BIND_TO_IP:
                $this->setBindToIp($value);
                break;
            case Options::EXPECT_CONTINUE:
                $this->setExpectContinue($value);
                break;
            case Options::IO_GRANULARITY:
                $this->setIoGranularity($value);
                break;
            case Options::AUTO_ENCODING:
                $this->setAutoEncoding($value);
                break;
            case Options::VERBOSE_READ:
                $this->setVerboseRead($value);
                break;
            case Options::VERBOSE_SEND:
                $this->setVerboseSend($value);
                break;
            case Options::TLS_OPTIONS:
                $this->setTlsOptions($value);
                break;
            case Options::USER_AGENT:
                $this->setUserAgentString($value);
                break;
            default:
                throw new \DomainException(
                    sprintf("Unknown Artax option: %s", $key)
                );
        }

        return $this;
    }

    private function setUserAgentString($value) {
        if ($value && is_string($value)) {
            $this->userAgentString = $value;
        } else {
            throw new \InvalidArgumentException(
                'User Agent requires a non-empty string'
            );
        }
    }

    private function setUseKeepAlive($bool) {
        $this->useKeepAlive = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setConnectTimeout($seconds) {
        $this->connectTimeout = filter_var($seconds, FILTER_VALIDATE_INT, array('options' => array(
            'default' => 5,
            'min_range' => -1
        )));
    }

    private function setTransferTimeout($seconds) {
        $this->transferTimeout = filter_var($seconds, FILTER_VALIDATE_INT, array('options' => array(
            'default' => 30,
            'min_range' => -1
        )));
    }

    private function setKeepAliveTimeout($seconds) {
        $this->keepAliveTimeout = filter_var($seconds, FILTER_VALIDATE_INT, array('options' => array(
            'default' => 30,
            'min_range' => 0
        )));
    }

    private function setFollowLocation($bool) {
        $this->followLocation = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setAutoReferer($bool) {
        $this->autoReferer = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setMaxConnections($int) {
        $this->maxConnections = filter_var($int, FILTER_VALIDATE_INT, array('options' => array(
            'default' => -1,
            'min_range' => -1
        )));
    }

    private function setMaxConnectionsPerHost($int) {
        $this->maxConnectionsPerHost = filter_var($int, FILTER_VALIDATE_INT, array('options' => array(
            'default' => 8,
            'min_range' => -1
        )));
    }

    private function setContinueDelay($seconds) {
        $this->continueDelay = filter_var($seconds, FILTER_VALIDATE_INT, array('options' => array(
            'default' => 3,
            'min_range' => 0
        )));
    }

    private function setBufferBody($bool) {
        $this->bufferBody = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setMaxHeaderBytes($bytes) {
        $this->maxHeaderBytes = (int) $bytes;
    }

    private function setMaxBodyBytes($bytes) {
        $this->maxBodyBytes = (int) $bytes;
    }

    private function setBodySwapSize($bytes) {
        $this->bodySwapSize = (int) $bytes;
    }

    private function setStoreBody($bool) {
        $this->storeBody = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setIoGranularity($bytes) {
        $this->ioGranularity = filter_var($bytes, FILTER_VALIDATE_INT, array('options' => array(
            'default' => 65536,
            'min_range' => 1
        )));
    }

    private function setBindToIp($ip) {
        $this->bindToIp = filter_var($ip, FILTER_VALIDATE_IP);
    }

    private function setExpectContinue($bool) {
        $this->expectContinue = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setAutoEncoding($bool) {
        $this->autoEncoding = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setVerboseRead($bool) {
        $this->verboseRead = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setVerboseSend($bool) {
        $this->verboseSend = filter_var($bool, FILTER_VALIDATE_BOOLEAN);
    }

    private function setTlsOptions(array $opt) {
        $opt = array_filter(array_intersect_key($opt, $this->tlsOptions), function($k) { return !is_null($k); });
        $this->tlsOptions = array_merge($this->tlsOptions, $opt);
    }

    /**
     * @TODO Refactor to allow for non-blocking DNS lookups. Currently this is the only part of the
     * process that has the potential to block because it relies on PHP for DNS lookups.
     */
    private function resolveDns(Cycle $cycle) {
        $urlParts = parse_url($cycle->authority);
        $ip = gethostbyname($urlParts['host']);

        if (filter_var($urlParts['host'], FILTER_VALIDATE_IP)) {
            $dnsLookupSucceeded = TRUE;
        } elseif (($ip = gethostbyname($urlParts['host'])) && ($ip === $urlParts['host'])) {
            $this->cycles->attach($cycle->request, $cycle);
            $this->onError($cycle, new DnsException(
                'DNS resolution failed for ' . $urlParts['host']
            ));
            $dnsLookupSucceeded = FALSE;
        } else {
            $cycle->authority = $ip . ':' . $urlParts['port'];
            $dnsLookupSucceeded = TRUE;
        }

        return $dnsLookupSucceeded;
    }

    private function generateAuthorityFromUri($uri) {
        $uriParts = parse_url($uri);
        $host = $uriParts['host'];
        $needsEncryption = (strtolower($uriParts['scheme']) === 'https');

        if (empty($uriParts['port'])) {
            $port = $needsEncryption ? 443 : 80;
        } else {
            $port = $uriParts['port'];
        }

        return $host . ':' . $port;
    }

    private function assignRequestSockets() {
        foreach ($this->requestQueue as $request) {
            $cycle = $this->requestQueue->offsetGet($request);

            if ($socket = $this->checkoutExistingSocket($cycle)) {
                $cycle->socket = $socket;
                $this->applyRequestSocketObservations($cycle);
                $this->requestQueue->detach($request);
                $this->cycles->attach($request, $cycle);
            } elseif ($socket = $this->checkoutNewSocket($cycle)) {
                $cycle->socket = $socket;
                $this->assignSocketOptions($request, $cycle->socket);
                $this->applyRequestSocketObservations($cycle);
                $this->requestQueue->detach($request);
                $this->cycles->attach($request, $cycle);
            }
        }
    }

    private function checkoutExistingSocket(Cycle $cycle) {
        foreach ($this->sockets as $socket) {
            $isInUse = (bool) $this->sockets->offsetGet($socket);
            $isAuthorityMatch = ($cycle->authority === $socket->getAuthority());

            if (!$isInUse && $isAuthorityMatch) {
                $this->sockets->attach($socket, $cycle);
                return $socket;
            }
        }
    }

    private function checkoutNewSocket(Cycle $cycle) {
        if ($this->isNewConnectionAllowed($cycle->authority)) {
            $socket = new Socket($this->reactor, $cycle->authority);
            $this->sockets->attach($socket, $cycle);

            return $socket;
        }
    }

    private function isNewConnectionAllowed($authority) {
        if ($this->maxConnections < 0 || ($this->sockets->count() < $this->maxConnections)) {
            $hostCount = 0;

            foreach ($this->sockets as $socket) {
                $hostCount += ($socket->getAuthority() === $authority);
            }

            $result = ($hostCount < $this->maxConnectionsPerHost);

        } else {
            $result = FALSE;
        }

        return $result;
    }

    private function assignSocketOptions(Request $request, Socket $socket) {
        $opts = [
            'connectTimeout' => $this->connectTimeout,
            'bindToIp' => $this->bindToIp,
        ];
        if (parse_url($request->getUri(), PHP_URL_SCHEME) === 'https'){
            $opts['tlsOptions'] = $this->tlsOptions;
        }

        $socket->setAllOptions($opts);
    }

    private function checkinSocket(Cycle $cycle) {
        if ($socket = $cycle->socket) {
            $isInUse = FALSE;
            $this->sockets->attach($socket, $isInUse);
        }
    }

    private function clearSocket(Cycle $cycle) {
        if ($socket = $cycle->socket) {
            $socket->stop();
            $socket->removeAllObservations();
            $this->sockets->detach($socket);
        }
    }

    private function applyRequestSocketObservations(Cycle $cycle) {
        $cycle->parser = new MessageParser(MessageParser::MODE_RESPONSE);
        $cycle->parser->setOptions([
            'maxHeaderBytes' => $this->maxHeaderBytes,
            'maxBodyBytes' => $this->maxBodyBytes,
            'bodySwapSize' => $this->bodySwapSize,
            'storeBody' => $this->storeBody,
            'beforeBody' => function($parsedResponseArr) use ($cycle) {
                $this->notifyObservations(Event::HEADERS, [$cycle->request, $parsedResponseArr]);
            },
            'onBodyData' => function($data) use ($cycle) {
                $this->notifyObservations(Event::BODY_DATA, [$cycle->request, $data]);
            }
        ]);

        $onSockReady = function() use ($cycle) { $this->onSockReady($cycle); };
        $onSockWrite = function($data) use ($cycle) { $this->onSockWrite($cycle, $data); };
        $onSockRead  = function($data) use ($cycle) { $this->onSockRead($cycle, $data); };
        $onSockError = function($e) use ($cycle) { $this->onError($cycle, $e); };

        $cycle->socketObservation = $cycle->socket->addObservation([
            Socket::READY => $onSockReady,
            Socket::SEND => $onSockWrite,
            Socket::DATA => $onSockRead,
            Socket::ERROR => $onSockError,
        ]);

        $cycle->socket->start();
    }

    private function onSockReady(Cycle $cycle) {
        $this->notifyObservations(Event::SOCKET, [$cycle->request, NULL]);
        $this->initializeRequestHeaderWrite($cycle);
    }

    private function onSockWrite(Cycle $cycle, $dataSent) {
        if ($this->verboseSend) {
            echo $dataSent;
        }

        $this->notifyObservations(Event::SOCK_DATA_OUT, [$cycle->request, $dataSent]);
    }

    private function onSockRead(Cycle $cycle, $data) {
        if ($this->verboseRead) {
            echo $data;
        }

        $this->notifyObservations(Event::SOCK_DATA_IN, [$cycle->request, $data]);
        $this->parseSockData($cycle, $data);
    }

    private function parseSockData(Cycle $cycle, $data) {
        try {
            while ($parsedResponseArr = $cycle->parser->parse($data)) {
                $cycle->response = $this->buildResponseFromParsedArray($cycle->request, $parsedResponseArr);
                if ($parsedResponseArr['status'] != 100) {
                    $this->onResponse($cycle);
                }
                $data = '';
            }
        } catch (MessageParseException $e) {
            $this->onError($cycle, $e);
        }
    }

    private function initializeRequestHeaderWrite(Cycle $cycle) {
        if ($this->transferTimeout > 0) {
            $cycle->transferTimeoutWatcher = $this->reactor->once(function() use ($cycle) {
                $this->onError($cycle, new TimeoutException);
            }, $this->transferTimeout * 1000);
        }

        $request = $cycle->request;
        $socket = $cycle->socket;

        $cycle->parser->enqueueResponseMethodMatch($request->getMethod());
        $rawHeaders = $this->generateRawRequestHeaders($request);

        if ($request->hasBody()) {
            $cycle->bodyDrainObservation = $socket->addObservation([
                Socket::DRAIN => function() use ($cycle) { $this->afterRequestHeaderWrite($cycle); }
            ]);
        }

        $socket->send($rawHeaders);
    }

    private function afterRequestHeaderWrite(Cycle $cycle) {
        if ($this->requestExpects100Continue($cycle->request)) {
            $cycle->continueDelayWatcher = $this->reactor->once(function() use ($cycle) {
                $this->initializeRequestBodyWrite($cycle);
            }, $this->continueDelay * 1000);
        } else {
            $this->initializeRequestBodyWrite($cycle);
        }
    }

    private function requestExpects100Continue(Request $request) {
        if (!$request->hasHeader('Expect')) {
            $expectsContinue = FALSE;
        } elseif (stristr(implode(',', $request->getHeader('Expect')), '100-continue')) {
            $expectsContinue = TRUE;
        } else {
            $expectsContinue = FALSE;
        }

        return $expectsContinue;
    }

    private function initializeRequestBodyWrite(Cycle $cycle) {
        $body = $cycle->request->getBody();
        $socket = $cycle->socket;

        if (is_string($body)) {
            // IMPORTANT: cancel the DRAIN observation BEFORE sending the body or we'll be stuck in
            // an infinite send/drain loop.
            $cycle->bodyDrainObservation->cancel();
            $socket->send($body);
        } elseif ($body instanceof \Iterator) {
            $cycle->bodyDrainObservation->modify([
                Socket::DRAIN => function() use ($cycle) { $this->streamIteratorRequestEntityBody($cycle); }
            ]);
            $this->streamIteratorRequestEntityBody($cycle);
        }
    }

    private function streamIteratorRequestEntityBody(Cycle $cycle) {
        $request = $cycle->request;
        $body = $request->getBody();

        if ($body->valid()) {
            $chunk = $body->current();
            $body->next();
            $socket = $cycle->socket;
            $socket->send($chunk);
        } else {
            $cycle->bodyDrainObservation->cancel();
        }
    }

    /**
     * @TODO Add support for sending proxy-style absolute URIs in the raw request message
     */
    private function generateRawRequestHeaders(Request $request) {
        $uri = $request->getUri();
        $uri = new Uri($uri);

        $requestUri = $uri->getPath() ?: '/';

        if ($query = $uri->getQuery()) {
            $requestUri .= '?' . $query;
        }

        $str = $request->getMethod() . ' ' . $requestUri . ' HTTP/' . $request->getProtocol() . "\r\n";

        foreach ($request->getAllHeaders() as $field => $valueArr) {
            foreach ($valueArr as $value) {
                $str .= "{$field}: {$value}\r\n";
            }
        }

        $str .= "\r\n";

        return $str;
    }

    private function onError(Cycle $cycle, \Exception $e) {
        $parser = $cycle->parser;

        if ($e->getCode() === Socket::E_SOCKET_GONE
            && $parser->getState() == MessageParser::BODY_IDENTITY_EOF
        ) {
            $this->finalizeBodyEofResponse($cycle);
        } else {
            $this->doError($cycle, $e);
        }

        if ($this->requestQueue->count()) {
            $this->assignRequestSockets();
        }
    }

    private function finalizeBodyEofResponse(Cycle $cycle) {
        $parser = $cycle->parser;
        $parsedResponseArr = $parser->getParsedMessageArray();
        $response = $this->buildResponseFromParsedArray($cycle->request, $parsedResponseArr);
        $cycle->response = $response;
        $this->onResponse($cycle);
    }

    private function doError(Cycle $cycle, \Exception $e) {
        $this->endRequestSubscriptions($cycle);

        $partialMsgArr = $cycle->parser ? $cycle->parser->getParsedMessageArray() : [];
        $this->notifyObservations(Event::ERROR, [$cycle->request, $partialMsgArr, $e]);

        // Only inform the error callback if event subscribers don't cancel the request
        if ($this->cycles->contains($cycle->request)) {
            $this->cycles->detach($cycle->request);
            $this->requestQueue->detach($cycle->request);

            $promise = $cycle->promise->fail($e);
        }
    }

    private function endRequestSubscriptions(Cycle $cycle) {
        $cycle->socket = NULL;

        if ($cycle->socketObservation) {
            $cycle->socketObservation->cancel();
            $cycle->socketObservation = NULL;
        }
        if ($cycle->bodyDrainObservation) {
            $cycle->bodyDrainObservation->cancel();
            $cycle->bodyDrainObservation = NULL;
        }
        if ($cycle->continueDelayWatcher) {
            $this->reactor->cancel($cycle->continueDelayWatcher);
            $cycle->continueDelayWatcher = NULL;
        }
        if ($cycle->transferTimeoutWatcher) {
            $this->reactor->cancel($cycle->transferTimeoutWatcher);
            $cycle->transferTimeoutWatcher = NULL;
        }
    }

    private function onResponse(Cycle $cycle) {
        $this->checkinSocket($cycle);

        if ($this->shouldCloseSocket($cycle)) {
            $this->clearSocket($cycle);
        }

        $this->endRequestSubscriptions($cycle);

        if ($this->hasExtZlib && $cycle->response->hasHeader('Content-Encoding')) {
            $this->inflateGzResponseBody($cycle->response);
        }

        if ($newUri = $this->getRedirectUri($cycle)) {
            $this->redirect($cycle, $newUri);
        } else {
            $this->notifyObservations(Event::RESPONSE, [$cycle->request, $cycle->response]);
            $this->cycles->detach($cycle->request);
            $this->requestQueue->detach($cycle->request);
            $cycle->promise->succeed($cycle->response);
        }

        if ($this->requestQueue->count()) {
            $this->assignRequestSockets();
        }
    }

    private function inflateGzResponseBody(Response $response) {
        $contentEncoding = trim(current($response->getHeader('Content-Encoding')));
        if (strcasecmp($contentEncoding, 'gzip')) {
            return;
        }

        $src = $response->getBody();

        if (is_resource($src)) {
            $destination = fopen('php://tmp', 'r+');
            fseek($src, 10, SEEK_SET);
            stream_filter_prepend($src, 'zlib.inflate', STREAM_FILTER_READ);
            stream_copy_to_stream($src, $destination);
            rewind($destination);
            $response->setBody($destination);
        } elseif (strlen($src)) {
            $body = gzdecode($src);
            $response->setBody($body);
        }
    }

    private function endContinueDelay(Request $request) {
        $cycle = $this->cycles->offsetGet($request);
        $this->reactor->cancel($cycle->continueDelayWatcher);
        $cycle->continueDelayWatcher = NULL;

        $this->initializeRequestBodyWrite($cycle);
    }

    private function buildResponseFromParsedArray(Request $request, array $parsedResponseArr) {
        if ($parsedResponseArr['status'] == 100) {
            $this->endContinueDelay($request);
            return NULL;
        }

        if (($body = $parsedResponseArr['body']) && $this->bufferBody) {
            $body = stream_get_contents($body);
        }

        $response = new Response;
        $response->setStatus($parsedResponseArr['status']);
        $response->setReason($parsedResponseArr['reason']);
        $response->setProtocol($parsedResponseArr['protocol']);
        $response->setBody($body);
        $response->setAllHeaders($parsedResponseArr['headers']);

        return $response;
    }

    private function shouldCloseSocket(Cycle $cycle) {
        $request = $cycle->request;
        $response = $cycle->response;

        $requestConnHeader = $request->hasHeader('Connection')
            ? current($request->getHeader('Connection'))
            : NULL;

        $responseConnHeader = $response->hasHeader('Connection')
            ? current($response->getHeader('Connection'))
            : NULL;

        if ($requestConnHeader && !strcasecmp($requestConnHeader, 'close')) {
            $result = TRUE;
        } elseif ($responseConnHeader && !strcasecmp($responseConnHeader, 'close')) {
            $result = TRUE;
        } elseif ($response->getProtocol() == '1.0' && !$responseConnHeader) {
            $result = TRUE;
        } elseif (!$this->useKeepAlive) {
            $result = TRUE;
        } else {
            $result = FALSE;
        }

        return $result;
    }

    private function getRedirectUri(Cycle $cycle) {
        $request = $cycle->request;
        $response = $cycle->response;

        if (!($this->followLocation && $response->hasHeader('Location'))) {
            return NULL;
        }

        $status = $response->getStatus();
        $method = $request->getMethod();

        if ($status < 200 || $status > 399 || !($method === 'GET' || $method === 'HEAD')) {
            return NULL;
        }

        $requestUri = new Uri($request->getUri());
        $redirectLocation = current($response->getHeader('Location'));

        if (!$requestUri->canResolve($redirectLocation)) {
            return NULL;
        }

        $newUri = $requestUri->resolve($redirectLocation);

        $cycle->redirectHistory[] = $request->getUri();

        return in_array($newUri->__toString(), $cycle->redirectHistory) ? NULL : $newUri;
    }

    private function redirect(Cycle $cycle, Uri $newUri) {
        $request = $cycle->request;

        $refererUri = $request->getUri();
        $redirectResponse = $cycle->response;

        $cycle->authority = $this->generateAuthorityFromUri($newUri);
        $request->setUri($newUri->__toString());
        $request->setHeader('Host', parse_url($cycle->authority, PHP_URL_HOST));

        if (($body = $request->getBody()) && is_resource($body)) {
            rewind($body);
        }

        if ($this->autoReferer) {
            $request->setHeader('Referer', $refererUri);
        }

        $this->requestQueue->attach($request, $cycle);
        $this->notifyObservations(Event::REDIRECT, [$request, $redirectResponse]);
    }

    private function normalizeRequest($request) {
        if (is_string($request)) {
            $uri = $this->buildUriFromString($request);
            $request = new Request;
        } elseif ($request instanceof Request) {
            $uri = $this->buildUriFromString((string) $request->getUri());
        } else {
            throw new \InvalidArgumentException(
                'Request must be a valid HTTP URI or Artax\Request instance'
            );
        }

        if ($uri) {
            $request->setUri($uri->__toString());
        } else {
            throw new \InvalidArgumentException(
                'Request must specify a valid HTTP URI'
            );
        }

        if (!$method = $request->getMethod()) {
            $method = 'GET';
            $request->setMethod($method);
        }

        if (!$request->hasHeader('User-Agent')) {
            $userAgentString = $this->userAgentString ?: Version::ARTAX_USER_AGENT;
            $request->setHeader('User-Agent', $userAgentString);
        }

        if (!$request->hasHeader('Host')) {
            $this->normalizeRequestHostHeader($request);
        }

        if (!$protocol = $request->getProtocol()) {
            $request->setProtocol('1.1');
        } elseif (!($protocol == '1.0' || $protocol == '1.1')) {
            throw new \InvalidArgumentException(
                'Invalid request protocol: ' . $protocol
            );
        }

        $body = $request->getBody();

        if ($body instanceof BodyAggregate) {
            $body = $this->normalizeBodyAggregateRequest($request);
        }

        if (empty($body) && $body !== '0' && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $request->setHeader('Content-Length', '0');
            $request->removeHeader('Transfer-Encoding');
        } elseif (is_scalar($body) && $body !== '') {
            $body = (string) $body;
            $request->setBody($body);
            $request->setHeader('Content-Length', strlen($body));
            $request->removeHeader('Transfer-Encoding');
        } elseif ($body instanceof \Iterator) {
            $this->normalizeIteratorBodyRequest($request);
        } elseif ($body !== NULL) {
            throw new \InvalidArgumentException(
                'Request entity body must be a scalar or Iterator'
            );
        }

        if ($body && $this->expectContinue && !$request->hasHeader('Expect')) {
            $request->setHeader('Expect', '100-continue');
        }

        if ($method === 'TRACE' || $method === 'HEAD' || $method === 'OPTIONS') {
            $request->setBody(NULL);
        }

        if (!$this->useKeepAlive) {
            $request->setHeader('Connection', 'close');
        }

        if ($this->autoEncoding && $this->hasExtZlib) {
            $request->setHeader('Accept-Encoding', 'gzip, identity');
        } elseif ($this->autoEncoding) {
            $request->removeHeader('Accept-Encoding');
        }

        return $request;
    }

    private function normalizeRequestHostHeader(Request $request) {
        $authority = $this->generateAuthorityFromUri($request->getUri());

        /**
         * Though servers are supposed to be able to handle standard port names on the end of the
         * Host header some fail to do this correctly. As a result, we strip the port from the end
         * if it's a standard 80/443
         */
        if (stripos($authority, ':80') || stripos($authority, ':443')) {
            $authority = parse_url($authority, PHP_URL_HOST);
        }

        $request->setHeader('Host', $authority);
    }

    private function normalizeBodyAggregateRequest(Request $request) {
        $body = $request->getBody();
        $request->setHeader('Content-Type', $body->getContentType());
        $aggregatedBody = $body->getBody();
        $request->setBody($aggregatedBody);

        return $aggregatedBody;
    }

    private function normalizeIteratorBodyRequest(Request $request) {
        $body = $request->getBody();

        if ($body instanceof \Countable) {
            $request->setHeader('Content-Length', $body->count());
            $request->removeHeader('Transfer-Encoding');
        } elseif ($request->getProtocol() >= 1.1) {
            $request->removeHeader('Content-Length');
            $request->setHeader('Transfer-Encoding', 'chunked');
            $chunkedBody = new ChunkingIterator($body);
            $request->setBody($chunkedBody);
        } else {
            $resourceBody = $this->bufferIteratorResource($body);
            $request->setHeader('Content-Length', $resourceBody->count());
            $request->setBody($resourceBody);
        }
    }

    private function bufferIteratorResource(\Iterator $body) {
        $tmp = fopen('php://temp', 'r+');
        foreach ($body as $part) {
            fwrite($tmp, $part);
        }
        rewind($tmp);

        return new ResourceBody($tmp);
    }

    private function buildUriFromString($str) {
        try {
            $uri = new Uri($str);
            $scheme = $uri->getScheme();
            return (($scheme === 'http' || $scheme === 'https') && $uri->getHost()) ? $uri : NULL;
        } catch (\DomainException $e) {
            return NULL;
        }
    }
}
