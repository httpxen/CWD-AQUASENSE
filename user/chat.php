<?php
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// Database configuration
$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$dbName = 'cwd_aquasense';

ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Catch runtime errors and convert to JSON
set_exception_handler(function ($e) {
    error_log("Uncaught exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error occurred. Please try again later.']);
    exit;
});

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$apiKey = $_ENV['OPENAI_API_KEY'] ?? null;
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error.']);
    exit;
}

// Connect to MySQL
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error.']);
    exit;
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['messages']) || !is_array($input['messages']) || empty($input['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid messages provided.']);
    exit;
}

// Extract user token (assumed to be sent in Authorization header)
$headers = getallheaders();
$token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;
if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication token required.']);
    exit;
}

// Verify user token and get user_id
try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE remember_token = :token AND token_expiry > NOW()");
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token.']);
        exit;
    }
    $userId = $user['id'];
} catch (PDOException $e) {
    error_log("Token verification failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Authentication error.']);
    exit;
}

// Extract the latest user message
$userMessage = end($input['messages'])['content'] ?? '';
if (empty($userMessage)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message content is empty.']);
    exit;
}

try {
    $client = \OpenAI::client($apiKey);

    // System prompt for complaint handling
    $systemPrompt = [
        'role' => 'system',
        'content' => 'You are a friendly and empathetic customer service chatbot for CWD. Understand and respond to complaints in English, Tagalog, or Taglish, matching the user\'s language and tone. Provide concise, helpful, and professional responses. Acknowledge the issue, apologize if appropriate, and offer a solution or next steps. Analyze the sentiment (Positive, Negative, Neutral). Categorize the complaint (e.g., Billing, Water Supply, Water Quality, Technical, Customer Service). Return a JSON response with: `response` (reply to user), `category` (complaint type), `sentiment` (Positive, Negative, Neutral), and `urgency` (Low, Medium, High).'
    ];
    $messages = array_merge([$systemPrompt], $input['messages']);

    // Make API call to OpenAI
    $result = $client->chat()->create([
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'max_tokens' => 250,
        'temperature' => 0.7,
    ]);

    $answer = $result->choices[0]->message->content ?? '{}';
    $answerData = json_decode($answer, true);

    // Validate JSON response
    if (!is_array($answerData) || !isset($answerData['response'], $answerData['category'], $answerData['sentiment'], $answerData['urgency'])) {
        throw new Exception('Invalid response format from AI.');
    }

    // Store complaint in database
    $stmt = $pdo->prepare("
        INSERT INTO complaints (user_id, category, description, sentiment, status)
        VALUES (:user_id, :category, :description, :sentiment, 'Pending')
    ");
    $stmt->execute([
        'user_id' => $userId,
        'category' => $answerData['category'],
        'description' => $userMessage,
        'sentiment' => $answerData['sentiment'],
    ]);

    // Optionally assign complaint to staff (e.g., based on category or urgency)
    if ($answerData['urgency'] === 'High') {
        $stmt = $pdo->prepare("
            INSERT INTO complaint_assignments (complaint_id, staff_id, status)
            SELECT LAST_INSERT_ID(), staff_id, 'Assigned'
            FROM staff
            WHERE role = 'Support' LIMIT 1
        ");
        $stmt->execute();
    }

    // Return chatbot response to user
    echo json_encode(['answer' => $answerData['response']]);

} catch (Throwable $e) {
    error_log("API request failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to process your request at this time.']);
}
?>