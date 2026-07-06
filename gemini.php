<?php
require 'config.php';

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=".$GEMINI_API_KEY;

$prompt = "Generate ONE random WBCHSE Class 12 Biology MCQ.

Rules:
- Class 12 WBCHSE syllabus
- Four options (A, B, C, D)
- Mention the correct answer
- Give a short explanation
- Return only plain text.";

$data = [
    "contents" => [
        [
            "parts" => [
                [
                    "text" => $prompt
                ]
            ]
        ]
    ]
];

$options = [
    "http" => [
        "header" => "Content-Type: application/json\r\n",
        "method" => "POST",
        "content" => json_encode($data),
        "ignore_errors" => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

echo $response;