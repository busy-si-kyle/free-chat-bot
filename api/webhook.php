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
    
    if (isset($received_message['text'])) {
        $user_message = $received_message['text'];
        error_log("User message: " . $user_message);
        
        // Show typing indicator while processing
        sendTypingIndicator($sender_psid, $access_token, 'typing_on');
        
        // Call Gemini AI Model
        $ai_reply = callGemini($user_message);
        error_log("AI reply length: " . strlen($ai_reply) . " characters");
        
        // Facebook Messenger has a 2000 character limit per message
        // Split into chunks if needed
        $chunks = splitMessage($ai_reply, 1900); // Use 1900 to be safe
        
        foreach ($chunks as $chunk) {
            $response = ['text' => $chunk];
            callSendAPI($sender_psid, $response, $access_token);
            
            // Small delay between messages to avoid rate limiting
            if (count($chunks) > 1) {
                usleep(500000); // 0.5 second delay
            }
        }
    }
}

function splitMessage($text, $max_length = 1900) {
    // If message is short enough, return as-is
    if (strlen($text) <= $max_length) {
        return [$text];
    }
    
    $chunks = [];
    $remaining = $text;
    
    while (strlen($remaining) > $max_length) {
        // Try to split at a natural break point (newline, period, space)
        $split_pos = $max_length;
        
        // Look for newline
        $last_newline = strrpos(substr($remaining, 0, $max_length), "\n");
        if ($last_newline !== false && $last_newline > $max_length * 0.7) {
            $split_pos = $last_newline + 1;
        } else {
            // Look for period + space
            $last_period = strrpos(substr($remaining, 0, $max_length), '. ');
            if ($last_period !== false && $last_period > $max_length * 0.7) {
                $split_pos = $last_period + 2;
            } else {
                // Look for any space
                $last_space = strrpos(substr($remaining, 0, $max_length), ' ');
                if ($last_space !== false && $last_space > $max_length * 0.5) {
                    $split_pos = $last_space + 1;
                }
            }
        }
        
        $chunks[] = trim(substr($remaining, 0, $split_pos));
        $remaining = substr($remaining, $split_pos);
    }
    
    // Add the remaining text
    if (strlen($remaining) > 0) {
        $chunks[] = trim($remaining);
    }
    
    return $chunks;
}

function sendTypingIndicator($sender_psid, $access_token, $action = 'typing_on') {
    $url = 'https://graph.facebook.com/v21.0/me/messages?access_token=' . $access_token;
    
    $request_body = [
        'recipient' => ['id' => $sender_psid],
        'sender_action' => $action  // 'typing_on', 'typing_off', or 'mark_seen'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    curl_exec($ch);
    curl_close($ch);
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
        'system_instruction' => [
            'parts' => [
                ['text' => 'You are a helpful assistant on Facebook Messenger. Keep your responses concise and to the point unless the user explicitly asks for detailed information. Use clear, conversational language. Break complex topics into digestible points.']
            ]
        ],
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
