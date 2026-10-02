<?php

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    exit(1);
}
echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1)."\n";
flush();
$client = stream_socket_accept($server, 10);
if ($client === false) {
    exit(2);
}
stream_set_timeout($client, 10);
$read = static function (int $length) use ($client): string {
    $bytes = '';
    while (strlen($bytes) < $length) {
        $chunk = fread($client, $length - strlen($bytes));
        if ($chunk === false || $chunk === '') {
            exit(3);
        }
        $bytes .= $chunk;
    }

    return $bytes;
};
if ($read(10) !== "zINSTREAM\0") {
    exit(4);
}
$hash = hash_init('sha256');
$chunks = 0;
while (($length = unpack('N', $read(4))[1]) !== 0) {
    hash_update($hash, $read($length));
    $chunks++;
}
fwrite($client, $argv[1]."\0");
fclose($client);
fclose($server);
echo json_encode(['sha256' => hash_final($hash), 'chunks' => $chunks]);
