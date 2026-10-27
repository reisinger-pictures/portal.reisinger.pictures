<?php

[$script, $host, $port, $mode, $certificatePath, $privateKeyPath] = $argv;

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $certificatePath,
        'local_pk' => $privateKeyPath,
        'disable_compression' => true,
    ],
]);
$server = stream_socket_server(
    sprintf('tcp://%s:%s', $host, $port),
    $errorNumber,
    $errorMessage,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context,
);

if (! is_resource($server)) {
    fwrite(STDERR, 'Could not bind test FTPS server.');
    exit(1);
}

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$client = @stream_socket_accept($server, 10);
if (! is_resource($client)) {
    fclose($server);
    exit(2);
}

stream_set_timeout($client, 5);

if ($mode === 'bare_lf') {
    // A reply that ends in LF instead of CRLF is malformed (RFC 959). The
    // client must reject it rather than treat the stray newline as the answer.
    fwrite($client, "220 Loopback FTPS ready\n");
    fflush($client);
    fclose($client);
    fclose($server);
    exit(0);
}

if ($mode === 'explicit') {
    fwrite($client, "220 Loopback FTPS ready\r\n");
    fflush($client);

    if (trim((string) fgets($client, 1024)) !== 'AUTH TLS') {
        fclose($client);
        fclose($server);
        exit(3);
    }

    fwrite($client, "234 AUTH TLS successful\r\n");
    fflush($client);
}

$crypto = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
if ($crypto !== true) {
    fclose($client);
    fclose($server);
    exit(4);
}

fclose($client);
fclose($server);
