<?php

require __DIR__ . '/../src/bootstrap.php';

$client = new Artax\Client;
$response = $client->request('http://httpbin.org/user-agent');

printf("HTTP/%s %s %s\n", $response->getProtocol(), $response->getStatus(), $response->getReason());
echo "---------------------- RESPONSE BODY ------------------\n";
echo $response->getBody(), "\n";
