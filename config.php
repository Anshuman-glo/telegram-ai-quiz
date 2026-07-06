<?php
declare(strict_types=1);

$BOT_TOKEN = getenv('BOT_TOKEN');
$CHANNEL_ID = getenv('CHANNEL_ID');
$GEMINI_API_KEY = getenv('GEMINI_API_KEY');

if (!$BOT_TOKEN || !$CHANNEL_ID || !$GEMINI_API_KEY) {
    die("Missing required environment variables.");
}
