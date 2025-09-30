<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");

// Catch ALL runtime errors and convert to JSON
set_exception_handler(function ($e) {
    error_log("Uncaught exception: " . $e->getMessage());
    echo json_encode(['error' => 'Server exception']);
    exit;
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    error_log("PHP error: $errstr in $errfile:$errline");
    echo json_encode(['error' => 'Server error']);
    exit;
});

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$apiKey = $_ENV['OPENAI_API_KEY'] ?? null;
if (!$apiKey) {
    echo json_encode(['error' => 'Missing OpenAI API key']);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['messages'])) {
    echo json_encode(['error' => 'No messages provided']);
    exit;
}

try {
    // ✅ Correct instantiation (no "use OpenAI;" needed)
    $client = \OpenAI::client($apiKey);

    $result = $client->chat()->create([
        'model' => 'gpt-3.5-turbo',
        'messages' => $input['messages'],
    ]);

    $answer = $result->choices[0]->message->content ?? '';
    echo json_encode(['answer' => $answer]);

} catch (Throwable $e) {
    error_log("API request failed: " . $e->getMessage());
    echo json_encode(['error' => 'API request failed']);
}
