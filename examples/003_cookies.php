<?php

use Artax\Client, Artax\Options, Artax\Ext\Cookies\CookieExtension;

require __DIR__ . '/../src/bootstrap.php';

$client = new Client;

// Enable the cookie extension
(new CookieExtension)->extend($client);

// Enable write our raw request messages to the console so we can see the
// so we can see the cookies are included in the second request.
$client->setOption(Options::VERBOSE_SEND, TRUE);

$response = $client->request('http://www.google.com/');
$response = $client->request('http://www.google.com/');
