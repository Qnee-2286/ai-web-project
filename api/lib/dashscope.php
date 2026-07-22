<?php
function dashscope_chat(array $llm, array $messages): array
{
    $apiKey = $llm['api_key'] ?? '';
    $endpoint = $llm['endpoint'] ?? 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions';
    $model = $llm['model'] ?? 'qwen-plus';
    if ($apiKey === '') {
        throw new RuntimeException('DashScope API Key is not configured');
    }
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'temperature' => (float)($llm['temperature'] ?? 0.3),
        'response_format' => ['type' => 'json_object'],
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ]),
            'content' => $body,
            'timeout' => 45,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($endpoint, false, $context);
    if ($raw === false) {
        throw new RuntimeException('DashScope request failed');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('DashScope response is invalid');
    }
    if (!empty($data['error'])) {
        $message = $data['error']['message'] ?? 'DashScope API error';
        throw new RuntimeException($message);
    }
    return $data;
}

function dashscope_message_content(array $response): string
{
    return (string)($response['choices'][0]['message']['content'] ?? '');
}

function parse_llm_json_object(string $content): array
{
    $trimmed = trim($content);
    $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
    $trimmed = preg_replace('/\s*```$/', '', $trimmed);
    $data = json_decode($trimmed, true);
    if (is_array($data)) {
        return $data;
    }
    $start = strpos($trimmed, '{');
    $end = strrpos($trimmed, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $data = json_decode(substr($trimmed, $start, $end - $start + 1), true);
        if (is_array($data)) {
            return $data;
        }
    }
    throw new RuntimeException('AI response JSON parse failed');
}
