<?php

/**
 * Clients accept option assignment via setOption() and setAllOptions(). Though the
 * defaults are generally fine, you can tweak any of the values presented below.
 */

use Artax\Client, Artax\Options;
 
require dirname(__DIR__) . '/autoload.php'; // <-- autoloader script

$client = new Client;

// Set one option value at a time:
$client->setOption(Options::MAX_CONNECTIONS_PER_HOST, 4);

// Set multiple option values:
$client->setAllOptions([
    Options::USE_KEEP_ALIVE             => true,
    Options::CONNECT_TIMEOUT            => 30,
    Options::TRANSFER_TIMEOUT           => -1,
    Options::KEEP_ALIVE_TIMEOUT         => 30,
    Options::FOLLOW_LOCATION            => true,
    Options::AUTO_REFERER               => true,
    Options::MAX_CONNECTIONS            => -1,
    Options::MAX_CONNECTIONS_PER_HOST   => 8,
    Options::CONTINUE_DELAY             => 2,
    Options::BUFFER_BODY                => true,
    //Options::MAX_HEADER_BYTES           => null,
    //Options::MAX_BODY_BYTES             => null,
    Options::BODY_SWAP_SIZE             => null,
    Options::STORE_BODY                 => true,
    Options::BIND_TO_IP                 => "",
    Options::EXPECT_CONTINUE            => true,
    Options::IO_GRANULARITY             => 32768,
    Options::AUTO_ENCODING              => null,
    Options::VERBOSE_READ               => false,
    Options::VERBOSE_SEND               => false,
    Options::TLS_OPTIONS                => [],
    Options::USER_AGENT                 => ""
/*
    'useKeepAlive'          => TRUE,    // Use persistent connections (when the remote server allows it)
    'connectTimeout'        => 15,      // Timeout connect attempts after N seconds
    'transferTimeout'       => 30,      // Timeout transfers after N seconds
    'keepAliveTimeout'      => 30,      // How long to retain socket connections after a keep-alive request
    'followLocation'        => TRUE,    // Transparently follow redirects
    'autoReferer'           => TRUE,    // Automatically set the Referer header when following Location headers
    'maxConnections'        => -1,      // Max number of simultaneous sockets allowed (unlimited by default)
    'maxConnectionsPerHost' => 8,       // Max number of simultaneous sockets allowed per unique host
    'continueDelay'         => 3,       // How many seconds to wait for a 100 Continue response if `Expect: 100-continue` header used
    'expectContinue'        => TRUE,    // Auto-add Expect: 100-continue header for requests with entity bodies
    'bufferBody'            => TRUE,    // TRUE to buffer response bodies as strings, FALSE to keep them as temp streams
    'bindToIp'              => NULL,    // Optionally bind request sockets to a specific local IP on your machine
    'ioGranularity'         => 65536,   // Max bytes to read/write per socket IO operation
    'verboseRead'           => FALSE,   // If TRUE, write all raw message data received to STDOUT
    'verboseSend'           => FALSE,   // If TRUE, write all raw message data sent to STDOUT
    'tlsOptions'            => [        // The default set of TLS options
        'verify_peer' => TRUE,
        'allow_self_signed' => FALSE,
        'cafile' => dirname(__DIR__) . '/certs/cacert.pem',
        'capath' => NULL,
        'local_cert' => NULL,
        'passphrase' => NULL,
        'CN_match' => NULL,
        'verify_depth' => NULL,
        'ciphers' => NULL,
        'SNI_enabled' => NULL,
        'SNI_server_name' => NULL
    ]
*/
]);

