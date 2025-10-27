<?php
declare(strict_types=1);
global $TELEGRAM_TOKEN, $DISCORD_WEBHOOK_URL;
global $WEBHOOK_SECRET;

use src\telegram\TelegramRelay;

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../src/bootstrap.php';

$raw = file_get_contents('php://input') ?: '';
$update = json_decode($raw, true);
if (!is_array($update)) {
    http_response_code(400);
    echo 'bad json';
    exit;
}

file_put_contents(__DIR__ . '/../last_update.json', $raw);
$relay = new TelegramRelay($TELEGRAM_TOKEN, $DISCORD_WEBHOOK_URL);
$relay->handleUpdate($update);

http_response_code(200);
echo 'ok';