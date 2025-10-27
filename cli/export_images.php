<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$API_ID  = (int)($_ENV['TELEGRAM_API_ID'] ?? 0);
$API_HASH = $_ENV['TELEGRAM_API_HASH'] ?? '';
$DISCORD_WEBHOOK = $_ENV['DISCORD_WEBHOOK_URL'] ?? '';
$DISCORD_MAX_MB  = (int)($_ENV['DISCORD_MAX_MB'] ?? 8);
if (!$API_ID || !$API_HASH || !$DISCORD_WEBHOOK) {
    fwrite(STDERR, "ENV error: set TELEGRAM_API_ID, TELEGRAM_API_HASH, DISCORD_WEBHOOK_URL\n");
    exit(1);
}

// Аргументы: --channel=@username | --channel="-1001234567890"
$opts = getopt('', ['channel::', 'since::', 'limit::']);
$channelArg = $opts['channel'] ?? ($_ENV['EXPORT_CHANNEL'] ?? null);
$sinceArg   = $opts['since']   ?? null;
$limitArg   = isset($opts['limit']) ? (int)$opts['limit'] : 0;

$sinceTs = 0;
if ($sinceArg) {
    $sinceTs = ctype_digit($sinceArg) ? (int)$sinceArg : strtotime($sinceArg);
    if ($sinceTs === false) $sinceTs = 0;
}

$sessionPath = __DIR__ . '/../var/madeline.session';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0750, true);
}

$settings = new \danog\MadelineProto\Settings\AppInfo();
$settings->setApiId($API_ID);
$settings->setApiHash($API_HASH);

$mp = new \danog\MadelineProto\API($sessionPath, $settings);

$mp->start();

if (!$channelArg) {
    fwrite(STDERR, "Usage: php cli/export_relay.php --channel=@username [--since=2025-01-01] [--limit=1000]\n");
    exit(1);
}

$peer = $mp->getId($channelArg);

$http = new Client(['timeout' => 30.0]);
$maxBytes = $DISCORD_MAX_MB * 1024 * 1024;

$offsetId = 0;
$totalSent = 0;

do {
    $batch = $mp->messages->getHistory([
        'peer'        => $peer,
        'offset_id'   => $offsetId,
        'offset_date' => 0,
        'add_offset'  => 0,
        'limit'       => 100,
        'max_id'      => 0,
        'min_id'      => 0,
        'hash'        => 0,
    ]);

    if (!isset($batch['messages']) || count($batch['messages']) === 0) {
        break;
    }

    $minIdInBatch = PHP_INT_MAX;
    foreach ($batch['messages'] as $m) {
        if (!isset($m['id'])) continue;
        $minIdInBatch = min($minIdInBatch, (int)$m['id']);

        $date = isset($m['date']) ? (int)$m['date'] : 0;
        if ($sinceTs && $date && $date < $sinceTs) {
            continue;
        }

        $hasPhoto = isset($m['media']['_']) && $m['media']['_'] === 'messageMediaPhoto';
        $hasDocImage = (
            isset($m['media']['_']) && $m['media']['_'] === 'messageMediaDocument' &&
            isset($m['media']['document']['mime_type']) &&
            str_starts_with($m['media']['document']['mime_type'], 'image/')
        );
        if (!$hasPhoto && !$hasDocImage) {
            continue;
        }

        $tmpDir = __DIR__ . '/../var/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0750, true);
        }
        try {
            $localPath = $mp->downloadToDir($m, $tmpDir);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Download error for msg {$m['id']}: {$e->getMessage()}\n");
            continue;
        }

        if (!is_file($localPath)) {
            continue;
        }

        $size = filesize($localPath) ?: 0;
        if ($size <= 0) {
            @unlink($localPath);
            continue;
        }
        if ($size > $maxBytes) {
            fwrite(STDERR, "Skip msg {$m['id']} — {$size} bytes > {$maxBytes}\n");
            @unlink($localPath);
            continue;
        }

        $caption = '';
        if (!empty($m['message']) && is_string($m['message'])) {
            $caption = $m['message'];
        }

        try {
            $resp = $http->post($DISCORD_WEBHOOK, [
                'multipart' => [
                    [
                        'name'     => 'payload_json',
                        'contents' => json_encode([
                            'content' => $caption !== '' ? ("**Подпись:** " . $caption) : null,
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                    [
                        'name'     => 'file',
                        'filename' => basename($localPath),
                        'contents' => fopen($localPath, 'rb'),
                    ],
                ],
            ]);
            $totalSent++;
        } catch (GuzzleException $e) {
            fwrite(STDERR, "Discord error for msg {$m['id']}: {$e->getMessage()}\n");
        } finally {
            @unlink($localPath);
        }

        if ($limitArg > 0 && $totalSent >= $limitArg) {
            break 2;
        }

        usleep(150000);
    }

    if ($minIdInBatch === PHP_INT_MAX || $minIdInBatch <= 1) {
        break;
    }
    $offsetId = $minIdInBatch;
} while (true);

echo "Done. Sent images: {$totalSent}\n";