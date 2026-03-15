<?php

declare(strict_types=1);

function jsonResponse(int $code, string $status, string $message, $data = null): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $payload = [
        'code' => $code,
        'status' => $status,
        'message' => $message,
    ];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}
