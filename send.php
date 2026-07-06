<?php
declare(strict_types=1);

/**
 * send.php
 *
 * Telegram Quiz Bot - Sends a random WBCHSE Class 12 MCQ as a
 * Telegram Quiz Poll, using Google's Gemini 2.5 Flash API to generate
 * the question.
 *
 * Requires: config.php defining $BOT_TOKEN, $CHANNEL_ID, $GEMINI_API_KEY
 *
 * Scheduling:
 *   - This script is expected to be invoked every 10 minutes (e.g. via
 *     a GitHub Actions cron job).
 *   - A state.json file tracks the day's randomized start time and
 *     which subject (out of the daily rotation) should be sent next.
 *   - Before the day's randomly chosen start time, the script exits
 *     immediately without contacting Gemini or Telegram.
 *   - One subject is sent per successful execution. After all subjects
 *     for the day have been sent, the script does nothing further
 *     until the next calendar day (Asia/Kolkata).
 */

mb_internal_encoding('UTF-8');
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/config.php';

/**
 * ---------------------------------------------------------------------
 * Daily subject rotation (order matters).
 * ---------------------------------------------------------------------
 */
$SUBJECTS = ['Biology', 'Mathematics', 'Chemistry', 'Physics', 'Bengali', 'English'];

/**
 * ---------------------------------------------------------------------
 * Helper: Fail with a readable error message and stop execution.
 * ---------------------------------------------------------------------
 */
function fail(string $message, array $context = []): void
{
    echo "ERROR: {$message}\n";
    if (!empty($context)) {
        echo "Context:\n";
        echo json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit(1);
}

/**
 * ---------------------------------------------------------------------
 * Helper: Perform an HTTP POST request using file_get_contents() with a
 * stream context. Returns the raw response body, or false on failure.
 * ---------------------------------------------------------------------
 */
function httpPostJson(string $url, array $payload, int $timeout = 30): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return [false, null, 'Failed to encode request payload as JSON: ' . json_last_error_msg()];
    }

    $options = [
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n" .
                               "Accept: application/json\r\n",
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true, // so we can read error bodies too
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    $httpCode = null;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $httpCode = (int)$m[1];
            }
        }
    }

    if ($response === false) {
        $error = error_get_last();
        return [false, $httpCode, $error['message'] ?? 'Unknown error during HTTP request.'];
    }

    return [true, $httpCode, $response];
}

/**
 * ---------------------------------------------------------------------
 * Scheduler Helper: Get the current date (Y-m-d) in Asia/Kolkata.
 * ---------------------------------------------------------------------
 */
function getTodayDateIST(): string
{
    $tz  = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $tz);
    return $now->format('Y-m-d');
}

/**
 * ---------------------------------------------------------------------
 * Scheduler Helper: Get the current DateTime in Asia/Kolkata.
 * ---------------------------------------------------------------------
 */
function getNowIST(): DateTime
{
    $tz = new DateTimeZone('Asia/Kolkata');
    return new DateTime('now', $tz);
}

/**
 * ---------------------------------------------------------------------
 * Scheduler Helper: Generate a random "HH:MM" start time between
 * 07:00 and 10:00 (inclusive) Asia/Kolkata time.
 * ---------------------------------------------------------------------
 */
function generateRandomStartTime(): string
{
    // 07:00 to 10:00 => 0 to 180 minutes offset.
    $offsetMinutes = random_int(0, 180);
    $hour   = 7 + intdiv($offsetMinutes, 60);
    $minute = $offsetMinutes % 60;
    return sprintf('%02d:%02d', $hour, $minute);
}

/**
 * ---------------------------------------------------------------------
 * Scheduler Helper: Load state.json, creating it with fresh defaults
 * if it does not exist or is invalid/stale (i.e. a new day has begun).
 * ---------------------------------------------------------------------
 */
function loadOrInitState(string $statePath): array
{
    $today = getTodayDateIST();

    $state = null;

    if (file_exists($statePath)) {
        $raw = @file_get_contents($statePath);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $state = $decoded;
            }
        }
    }

    $needsReset = false;

    if ($state === null) {
        $needsReset = true;
    } else {
        // Validate expected keys are present.
        if (!isset($state['date'], $state['start_time'], $state['subject_index'], $state['completed'])) {
            $needsReset = true;
        } elseif ($state['date'] !== $today) {
            // A new day has begun.
            $needsReset = true;
        }
    }

    if ($needsReset) {
        $state = [
            'date'          => $today,
            'start_time'    => generateRandomStartTime(),
            'subject_index' => 0,
            'completed'     => false,
        ];
        saveState($statePath, $state);
    }

    return $state;
}

/**
 * ---------------------------------------------------------------------
 * Scheduler Helper: Persist state.json.
 * ---------------------------------------------------------------------
 */
function saveState(string $statePath, array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @file_put_contents($statePath, $json);
    }
}

/**
 * ---------------------------------------------------------------------
 * Step 0: Load / initialize the daily scheduler state.
 * ---------------------------------------------------------------------
 */
$statePath = __DIR__ . '/state.json';
$state     = loadOrInitState($statePath);

// If today's subject rotation is already completed, do nothing further.
if ($state['completed'] === true) {
    echo "INFO: All subjects already sent for {$state['date']}. Nothing to do until tomorrow.\n";
    exit(0);
}

// If we're before today's randomly chosen start time, exit immediately
// without contacting Gemini or Telegram.
$now = getNowIST();
[$startHour, $startMinute] = array_map('intval', explode(':', $state['start_time']));
$startDateTime = clone $now;
$startDateTime->setTime($startHour, $startMinute, 0);

if ($now < $startDateTime) {
    echo "INFO: Current time (" . $now->format('H:i') . ") is before today's start time ({$state['start_time']}). Exiting.\n";
    exit(0);
}

// Guard against an out-of-range subject_index (defensive; should not happen).
if (!isset($SUBJECTS[$state['subject_index']])) {
    echo "INFO: Subject index out of range; marking day as completed.\n";
    $state['completed'] = true;
    saveState($statePath, $state);
    exit(0);
}

/**
 * Change this to switch subjects later (e.g. "Physics", "Chemistry", "History").
 * Now driven by the daily rotation / scheduler state.
 */
$SUBJECT = $SUBJECTS[$state['subject_index']];

/**
 * ---------------------------------------------------------------------
 * Step 1: Validate configuration.
 * ---------------------------------------------------------------------
 */
if (!isset($BOT_TOKEN) || trim((string)$BOT_TOKEN) === '') {
    fail('Missing $BOT_TOKEN in config.php');
}
if (!isset($CHANNEL_ID) || trim((string)$CHANNEL_ID) === '') {
    fail('Missing $CHANNEL_ID in config.php');
}
if (!isset($GEMINI_API_KEY) || trim((string)$GEMINI_API_KEY) === '') {
    fail('Missing $GEMINI_API_KEY in config.php');
}

/**
 * ---------------------------------------------------------------------
 * Step 2: Build the Gemini prompt.
 * ---------------------------------------------------------------------
 */
if ($SUBJECT === 'Bengali') {
    // Bengali-specific prompt: question and options must be generated
    // in Bengali (Unicode), per the standard WBCHSE Bengali syllabus.
    // JSON format/keys remain unchanged (in English).
    $prompt = <<<PROMPT
You are a question generator for WBCHSE (West Bengal Council of Higher Secondary Education) Class 12 board exam preparation.

Generate ONE random multiple-choice question (MCQ) for the subject: Bengali (বাংলা), strictly based on the standard WBCHSE Class 12 Bengali syllabus.

Rules:
- The question text AND all options MUST be written in Bengali, using proper Unicode Bengali script (বাংলা লিপি). Do not use English or transliteration.
- Provide exactly 4 options, all in Bengali.
- Only one option must be correct.
- The question text must be under 290 characters.
- Each option must be under 95 characters.
- Do NOT include explanations, extra text, markdown formatting, or code fences.
- The JSON keys themselves ("question", "options", "correct") must remain in English exactly as shown below, but their values (the question text and options) must be in Bengali.
- Return ONLY a single valid JSON object, with no surrounding text, in EXACTLY this format:

{"question":"...","options":["...","...","...","..."],"correct":0}

Where "correct" is the zero-based index (0, 1, 2, or 3) of the correct option in the "options" array.
PROMPT;
} else {
    $prompt = <<<PROMPT
You are a question generator for WBCHSE (West Bengal Council of Higher Secondary Education) Class 12 board exam preparation.

Generate ONE random multiple-choice question (MCQ) for the subject: {$SUBJECT}.

Rules:
- The question must be strictly based on the WBCHSE Class 12 {$SUBJECT} syllabus.
- Provide exactly 4 options.
- Only one option must be correct.
- The question text must be under 290 characters.
- Each option must be under 95 characters.
- Do NOT include explanations, extra text, markdown formatting, or code fences.
- Return ONLY a single valid JSON object, with no surrounding text, in EXACTLY this format:

{"question":"...","options":["...","...","...","..."],"correct":0}

Where "correct" is the zero-based index (0, 1, 2, or 3) of the correct option in the "options" array.
PROMPT;
}

/**
 * ---------------------------------------------------------------------
 * Step 3: Call the Gemini 2.5 Flash API.
 * ---------------------------------------------------------------------
 */
$geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . rawurlencode($GEMINI_API_KEY);

$geminiPayload = [
    'contents' => [
        [
            'role'  => 'user',
            'parts' => [
                ['text' => $prompt],
            ],
        ],
    ],
    'generationConfig' => [
        'temperature'      => 1.0,
        'maxOutputTokens'  => 1024,
        'responseMimeType' => 'application/json',
    ],
];

[$geminiOk, $geminiHttpCode, $geminiRaw] = httpPostJson($geminiUrl, $geminiPayload, 40);

if (!$geminiOk) {
    fail('Failed to contact Gemini API.', ['reason' => $geminiRaw]);
}

$geminiDecoded = json_decode((string)$geminiRaw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($geminiDecoded)) {
    fail('Gemini API returned a non-JSON or malformed response.', [
        'http_code'     => $geminiHttpCode,
        'raw_response'  => $geminiRaw,
    ]);
}

if ($geminiHttpCode !== null && $geminiHttpCode >= 400) {
    fail('Gemini API returned an error response.', [
        'http_code' => $geminiHttpCode,
        'response'  => $geminiDecoded,
    ]);
}

/**
 * ---------------------------------------------------------------------
 * Step 4: Extract the generated text from Gemini's response structure.
 * ---------------------------------------------------------------------
 */
$generatedText = $geminiDecoded['candidates'][0]['content']['parts'][0]['text'] ?? null;

if (!is_string($generatedText) || trim($generatedText) === '') {
    fail('Gemini API response did not contain any generated text.', [
        'response' => $geminiDecoded,
    ]);
}

/**
 * ---------------------------------------------------------------------
 * Step 5: Safely parse the JSON MCQ returned by Gemini.
 * ---------------------------------------------------------------------
 */
$cleanText = trim($generatedText);

// Strip accidental markdown code fences, just in case.
$cleanText = preg_replace('/^```(?:json)?\s*/i', '', $cleanText);
$cleanText = preg_replace('/```\s*$/', '', $cleanText);
$cleanText = trim((string)$cleanText);

// If there's any stray text around the JSON object, try to isolate it.
if ($cleanText === '' || $cleanText[0] !== '{') {
    $start = strpos($cleanText, '{');
    $end   = strrpos($cleanText, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $cleanText = substr($cleanText, $start, $end - $start + 1);
    }
}

$mcq = json_decode($cleanText, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($mcq)) {
    fail('Gemini returned invalid JSON for the MCQ.', [
        'json_error'    => json_last_error_msg(),
        'raw_text'      => $generatedText,
    ]);
}

/**
 * ---------------------------------------------------------------------
 * Step 6: Validate the MCQ structure before sending to Telegram.
 * ---------------------------------------------------------------------
 */
if (!isset($mcq['question']) || !is_string($mcq['question']) || trim($mcq['question']) === '') {
    fail('Parsed MCQ is missing a valid "question" field.', ['mcq' => $mcq]);
}

if (!isset($mcq['options']) || !is_array($mcq['options']) || count($mcq['options']) !== 4) {
    fail('Parsed MCQ must contain exactly 4 options.', ['mcq' => $mcq]);
}

foreach ($mcq['options'] as $idx => $option) {
    if (!is_string($option) || trim($option) === '') {
        fail("MCQ option at index {$idx} is invalid or empty.", ['mcq' => $mcq]);
    }
}

if (!isset($mcq['correct']) || !is_int($mcq['correct']) && !ctype_digit((string)$mcq['correct'])) {
    fail('Parsed MCQ is missing a valid "correct" field.', ['mcq' => $mcq]);
}

$correctIndex = (int)$mcq['correct'];

if ($correctIndex < 0 || $correctIndex > 3) {
    fail('The "correct" index must be between 0 and 3.', ['mcq' => $mcq]);
}

/**
 * ---------------------------------------------------------------------
 * Step 7: Enforce Telegram's length limits for quiz polls.
 *   - question: max 300 chars
 *   - each option: max 100 chars
 * ---------------------------------------------------------------------
 */
$question = trim($mcq['question']);
if (mb_strlen($question) > 300) {
    $question = mb_substr($question, 0, 297) . '...';
}

$options = [];
foreach ($mcq['options'] as $option) {
    $opt = trim($option);
    if (mb_strlen($opt) > 100) {
        $opt = mb_substr($opt, 0, 97) . '...';
    }
    $options[] = $opt;
}

/**
 * ---------------------------------------------------------------------
 * Step 8: Send the Quiz Poll via Telegram Bot API (sendPoll).
 * ---------------------------------------------------------------------
 */
$telegramUrl = "https://api.telegram.org/bot{$BOT_TOKEN}/sendPoll";

$telegramPayload = [
    'chat_id'                 => $CHANNEL_ID,
    'question'                => $question,
    'options'                 => $options,
    'type'                    => 'quiz',
    'is_anonymous'            => true,
    'allows_multiple_answers' => false,
    'correct_option_id'       => $correctIndex,
    'explanation'             => '',
    'explanation_parse_mode'  => 'HTML',
    'open_period'             => null,
    'close_date'              => null,
];

// Remove null fields; Telegram does not accept explicit nulls for these.
foreach (['open_period', 'close_date'] as $nullableField) {
    if ($telegramPayload[$nullableField] === null) {
        unset($telegramPayload[$nullableField]);
    }
}

[$tgOk, $tgHttpCode, $tgRaw] = httpPostJson($telegramUrl, $telegramPayload, 30);

if (!$tgOk) {
    fail('Failed to contact Telegram API.', ['reason' => $tgRaw]);
}

$tgDecoded = json_decode((string)$tgRaw, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($tgDecoded)) {
    fail('Telegram API returned a non-JSON or malformed response.', [
        'http_code'    => $tgHttpCode,
        'raw_response' => $tgRaw,
    ]);
}

if (!isset($tgDecoded['ok']) || $tgDecoded['ok'] !== true) {
    fail('Telegram API returned an error.', [
        'http_code' => $tgHttpCode,
        'response'  => $tgDecoded,
    ]);
}

/**
 * ---------------------------------------------------------------------
 * Step 8.5: Advance the scheduler state after a successful send.
 *   - Increment subject_index so the next execution moves to the next
 *     subject in the rotation.
 *   - Never send the same subject twice in one day.
 *   - After English (the last subject), mark the day as completed.
 * ---------------------------------------------------------------------
 */
$state['subject_index']++;
if ($state['subject_index'] >= count($SUBJECTS)) {
    $state['completed'] = true;
}
saveState($statePath, $state);

/**
 * ---------------------------------------------------------------------
 * Step 9: Success output.
 * ---------------------------------------------------------------------
 */
echo "SUCCESS: Quiz poll sent successfully.\n";
echo "Subject: {$SUBJECT}\n";
echo "Question: {$question}\n";
echo "Message ID: " . ($tgDecoded['result']['message_id'] ?? 'unknown') . "\n";