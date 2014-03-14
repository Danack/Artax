<?php

namespace Artax;

class Cycle {
    public $promise;
    public $response;
    public $request;
    public $authority;
    public $socket;
    public $socketObservation;
    public $parser;
    public $redirectHistory = [];
    public $transferTimeoutWatcher;
    public $continueDelayWatcher;
    public $bodyDrainObservation;
}
