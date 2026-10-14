<?php

// A stand-in for api.anthropic.com for tests: records the request it received and answers with a Messages API reply.
// Started with `php -S`. MOCK_OUT names the file the request is written to; MOCK_MODE picks the reply.
$body = file_get_contents('php://input');
file_put_contents(getenv('MOCK_OUT'), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'], 'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'headers' => array_change_key_case(getallheaders() ?: [], CASE_LOWER), 'body' => json_decode($body, true),
]));
header('Content-Type: application/json');
$mode = getenv('MOCK_MODE') ?: 'ok';
if ($mode === 'rate_limit') {
    http_response_code(429);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'slow down']]);

    return;
}
if ($mode === 'auth') {
    http_response_code(401);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'bad key']]);

    return;
}
echo json_encode([
    'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
    'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => '{"verdict":"pass","issues":[],"unsupported_claims":[]}']],
    'stop_reason' => $mode === 'refusal' ? 'refusal' : ($mode === 'max_tokens' ? 'max_tokens' : 'end_turn'), 'stop_sequence' => null,
    'usage' => ['input_tokens' => 321, 'output_tokens' => 45, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
]);
