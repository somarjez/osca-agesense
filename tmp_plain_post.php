<?php
$payload = json_encode(['age' => 70]);
$opts = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 10,
    ],
];
$ctx = stream_context_create($opts);
$start = microtime(true);
$result = @file_get_contents('http://127.0.0.1:5001/preprocess', false, $ctx);
$elapsed = round(microtime(true) - $start, 3);
if ($result === false) {
    echo "FAILED elapsed={$elapsed}s\n";
    $err = error_get_last();
    echo ($err['message'] ?? 'unknown'), "\n";
} else {
    echo "OK elapsed={$elapsed}s\n";
    echo $result, "\n";
}
