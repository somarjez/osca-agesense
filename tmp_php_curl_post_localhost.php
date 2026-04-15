<?php
$ch = curl_init('http://localhost:5001/preprocess');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{"age":70,"first_name":"A","last_name":"B","barangay":"X"}');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$out = curl_exec($ch);
if ($out === false) {
    echo 'ERR: ' . curl_error($ch) . PHP_EOL;
} else {
    echo 'OK: ' . $out . PHP_EOL;
}
curl_close($ch);
