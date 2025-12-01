<?php

// Load environment variables (Vercel injects these automatically in production)
// For local development, you might need a library or manual parsing if not using Vercel CLI 'vercel dev'

$verify_token = getenv('FACEBOOK_VERIFY_TOKEN');
$access_token = getenv('FACEBOOK_PAGE_ACCESS_TOKEN');


// 1. Webhook Verification (GET Request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode && $token) {
        if ($mode === 'subscribe' && $token === $verify_token) {
            http_response_code(200);
            echo $challenge;
        } else {
            http_response_code(403);
            echo 'Forbidden';
        }
    }
    exit;
}

// 2. Message Handling (POST Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $body = json_decode($input, true);

    if ($body['object'] === 'page') {
        foreach ($body['entry'] as $entry) {
            $webhook_event = $entry['messaging'][0];
            $sender_psid = $webhook_event['sender']['id'];

            if (isset($webhook_event['message'])) {
                handleMessage($sender_psid, $webhook_event['message'], $access_token);
            }
        }
        http_response_code(200);
        echo 'EVENT_RECEIVED';
    } else {
        http_response_code(404);
    }
    exit;
}

function handleMessage($sender_psid, $received_message, $access_token) {
    error_log("=== WEBHOOK: Message received from $sender_psid ===");
    $response = [];

    if (isset($received_message['text'])) {
        $user_message = $received_message['text'];
        error_log("User message: " . $user_message);
        
        // Call Gemini AI Model
        $ai_reply = callGemini($user_message);
        error_log("AI reply: " . $ai_reply);
        
        $response = [
            'text' => $ai_reply
        ];
    }

    callSendAPI($sender_psid, $response, $access_token);
}

function callGemini($message) {
    $keys = [
        getenv('GEMINI_API_KEY_MAIN'),
        getenv('GEMINI_API_KEY_BACKUP')
    ];
    // Try Pro first, then Flash on rate limit
    $models = ['gemini-2.5-pro', 'gemini-2.5-flash'];

    foreach ($keys as $key_index => $key) {
        if (empty($key)) {
            error_log("Gemini key " . ($key_index + 1) . " is empty, skipping");
            continue;
        }
        
        foreach ($models as $model) {
            error_log("Trying $model with key " . ($key_index + 1));
            $response = makeGeminiRequest($message, $key, $model);
            
            if ($response['success']) {
                error_log("SUCCESS with $model");
                return $response['text'];
            }
            
            error_log("FAILED with $model - Code: " . ($response['code'] ?? 'unknown'));
        }
    }

    error_log("All Gemini attempts failed, returning error message");
    return "Sorry, I'm having trouble connecting to the AI right now.";
}

function makeGeminiRequest($message, $api_key, $model) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $message]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $response_data = json_decode($result, true);
        $text = $response_data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text) {
            return ['success' => true, 'text' => $text];
        }
    }

    error_log("Gemini API Error - Code: $http_code, Response: " . $result);
    return ['success' => false, 'code' => $http_code];
}

function callSendAPI($sender_psid, $response, $access_token) {
    $url = 'https://graph.facebook.com/v21.0/me/messages?access_token=' . $access_token;
    
    $request_body = [
        'recipient' => ['id' => $sender_psid],
        'message' => $response
    ];

    error_log("Sending to Facebook: " . json_encode($request_body));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("Facebook API Response - Code: $http_code, Response: " . $result);
}
?>
