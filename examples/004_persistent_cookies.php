<?php

use Artax\Client,
    Artax\Ext\Cookies\CookieExtension,
    Artax\Ext\Cookies\FileCookieJar;

require dirname(__DIR__) . '/autoload.php'; // <-- autoloader script

$client = new Client;

// By using the FileCookieJar we can persist cookies beyond the life of the current client instance.
$cookieJar = new FileCookieJar('/hard/path/to/cookies.txt');
(new CookieExtension($cookieJar))->extend($client);

$response = $client->request('http://www.google.com/');
