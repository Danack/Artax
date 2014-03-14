<?php

use Artax\Client, Artax\Request, Artax\ClientException;

require __DIR__ . '/../src/bootstrap.php';

$client = new Client;
$request = (new Request)
    ->setUri('http://httpbin.org/post')
    ->setMethod('POST')
    ->setBody('zanzibar!');

$response = $client->request($request);

echo 'HTTP/' , $response->getProtocol() , ' ' , $response->getStatus() , ' ' , $response->getReason() , "\n";
echo "---------------------- RESPONSE BODY ------------------\n";
echo $response->getBody(), "\n";
