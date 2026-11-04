<?php
/**
 * api/auth_middleware.php
 * Validates the Bearer token sent from the Flutter app.
 * Tries multiple methods to get Authorization header (Apache strips it sometimes).
 */

function validateToken(): array {
    $secret = 'pharmacy_mobile_secret_key_2024';

    // Method 1: Standard
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    // Method 2: Redirect header (common with Apache mod_rewrite)
    if (empty($authHeader)) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }

    // Method 3: apache_request_headers() fallback
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $authHeader = $value;
                break;
            }
        }
    }

    // Method 4: getallheaders() fallback
    if (empty($authHeader) && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $authHeader = $value;
                break;
            }
        }
    }

    if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No token provided']);
        exit;
    }

    $rawToken = substr($authHeader, 7);
    $parts    = explode('.', $rawToken, 2);
    if (count($parts) !== 2) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid token format']);
        exit;
    }

    [$encodedPayload, $signature] = $parts;
    $expectedSig = hash_hmac('sha256', $encodedPayload, $secret);

    if (!hash_equals($expectedSig, $signature)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid token signature']);
        exit;
    }

    $payload = json_decode(base64_decode($encodedPayload), true);
    if (!$payload || $payload['exp'] < time()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token expired']);
        exit;
    }

    return $payload;
}