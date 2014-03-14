<?php

/**
 * Artax simplifies HTML form submissions. Simply use the built-in Artax\Entity\FormBody class to
 * construct your form values and you're finished. There's no need to understand the intricacies
 * of the multipart/form-data or application/x-www-form-urlencoded MIME types.
 * 
 * **IMPORTANT:** Note that any files you send as part of the form submission are *always* streamed
 * to minimize memory use regardless of the HTTP protocol in use (1.0/1.1).
 */

use Artax\Client, Artax\Request, Artax\Entity\FormBody;

require dirname(__DIR__) . '/autoload.php'; // <-- autoloader script

$body = new FormBody;
$body->addField('field1', 'val1');
$body->addFileField('file1', __DIR__ . '/../test/fixture/lorem.txt');
$body->addFileField('file2', __DIR__ . '/../test/fixture/answer.txt');

$client = new Client;
$request = (new Request)
    ->setBody($body)
    ->setUri('http://httpbin.org/post')
    ->setMethod('POST');

$response = $client->request($request);

// httbin.org sends us a JSON response summarizing our data
echo $response->getBody();
