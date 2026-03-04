<?php
$UPSTREAM_BASE = "http://127.0.0.1";

$requestUri  = $_SERVER["REQUEST_URI"];
$upstreamUrl = $UPSTREAM_BASE . $requestUri;

$method = $_SERVER["REQUEST_METHOD"];
$body   = file_get_contents("php://input");

$headerLines = [];
foreach (getallheaders() as $name => $value) {
    if (strtolower($name) === "host") continue;
    $headerLines[] = "$name: $value";
}

if (!empty($_COOKIE)) {
    $pairs = [];
    foreach ($_COOKIE as $k => $v) {
        $pairs[] = "$k=$v";
    }
    $headerLines[] = "Cookie: " . implode("; ", $pairs);
}

$context = stream_context_create([
    "http" => [
        "method"        => $method,
        "header"        => implode("\r\n", $headerLines),
        "content"       => $body,
        "ignore_errors" => true,
        "timeout"       => 3 // seconds, adjust as needed
    ]
]);

ignore_user_abort(true);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 'off');

$upstreamHandle = @fopen($upstreamUrl, 'r', false, $context);
if ($upstreamHandle) {
    while (!feof($upstreamHandle)) {
        $chunk = fread($upstreamHandle, 8192);
        if ($chunk === false) break;
        echo $chunk;
        flush();
    }
    fclose($upstreamHandle);
} else {
    http_response_code(502);
    echo "Failed to fetch upstream.";
}
