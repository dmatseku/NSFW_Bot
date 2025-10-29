<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Revolt\EventLoop;
use Amp\SignalException;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

EventLoop::setErrorHandler(function (\Throwable $e): void {
    if ($e instanceof SignalException) {
        fwrite(STDERR, "SIGINT received, exiting...\n");
        exit(130);
    }
    fwrite(STDERR, "Unhandled event-loop error: ".$e->getMessage()."\n");
    exit(1);
});
EventLoop::onSignal(SIGINT, function (): void {
    fwrite(STDERR, "SIGINT caught, exiting...\n");
    exit(130);
});
EventLoop::onSignal(SIGTERM, function (): void {
    fwrite(STDERR, "SIGTERM caught, exiting...\n");
    exit(143);
});

$API_ID               = (int)($_ENV['TELEGRAM_API_ID'] ?? 0);
$API_HASH             = $_ENV['TELEGRAM_API_HASH'] ?? '';
$DISCORD_WEBHOOK      = $_ENV['DISCORD_WEBHOOK_URL'] ?? '';
$DISCORD_MAX_MB       = (int)($_ENV['DISCORD_MAX_MB'] ?? 8);
$GROUPS_PER_PERIOD    = (int)($_ENV['EXPORT_GROUPS_PER_PERIOD'] ?? 200);
$PERIOD_SEC           = (int)($_ENV['EXPORT_PERIOD_SEC'] ?? 86400);

if (!$API_ID || !$API_HASH || !$DISCORD_WEBHOOK) {
    fwrite(STDERR, "ENV error: set TELEGRAM_API_ID, TELEGRAM_API_HASH, DISCORD_WEBHOOK_URL\n");
    exit(1);
}

$opts         = getopt('', ['channel::', 'since::', 'limit::', 'from-id::', 'checkpoint::']);
$channelArg   = $opts['channel'] ?? ($_ENV['EXPORT_CHANNEL'] ?? null);
$sinceArg     = $opts['since']   ?? null;
$limitArg     = isset($opts['limit']) ? (int)$opts['limit'] : 0;
$fromIdArg    = isset($opts['from-id']) ? (int)$opts['from-id'] : 0;
$checkpoint   = $opts['checkpoint'] ?? (__DIR__ . '/../var/export_last_id.txt');

if (!$channelArg) {
    fwrite(STDERR, "Usage: php cli/export_images.php --channel=@username [--since=2025-01-01] [--from-id=123456] [--limit=1000] [--checkpoint=/path/to/file]\n");
    exit(1);
}

$sinceTs = 0;
if ($sinceArg) {
    $sinceTs = ctype_digit($sinceArg) ? (int)$sinceArg : strtotime($sinceArg);
    if ($sinceTs === false) $sinceTs = 0;
}

$varDir = __DIR__ . '/../var';
$tmpDir = $varDir . '/tmp';
if (!is_dir($varDir)) { mkdir($varDir, 0750, true); }
if (!is_dir($tmpDir)) { mkdir($tmpDir, 0750, true); }

$sessionFile = $varDir . '/madeline.session';
$settings = new \danog\MadelineProto\Settings\AppInfo();
$settings->setApiId($API_ID);
$settings->setApiHash($API_HASH);
$mp = new \danog\MadelineProto\API($sessionFile, $settings);
$mp->start();

$peer = $mp->getId($channelArg);

$http     = new Client(['timeout' => 30.0]);
$maxBytes = $DISCORD_MAX_MB * 1024 * 1024;

$totalSentFiles  = 0;
$totalSentGroups = 0;

$pendingGroupId = null;
$pendingFiles   = [];
$pendingCaption = '';

$periodStart = time();
$groupsInWindow = 0;
$throttle = function() use (&$periodStart, &$groupsInWindow, $GROUPS_PER_PERIOD, $PERIOD_SEC) {
    $now = time();
    $elapsed = $now - $periodStart;
    if ($elapsed >= $PERIOD_SEC) {
        $periodStart = $now;
        $groupsInWindow = 0;
        return;
    }
    if ($groupsInWindow >= $GROUPS_PER_PERIOD) {
        $sleep = $PERIOD_SEC - $elapsed;
        if ($sleep > 0) { sleep($sleep); }
        $periodStart = time();
        $groupsInWindow = 0;
    }
};

$saveCheckpoint = function(int $id) use ($checkpoint) {
    @file_put_contents($checkpoint, (string)$id, LOCK_EX);
    echo "checkpoint_saved: {$id}\n";
};
$loadCheckpoint = function() use ($checkpoint): int {
    if (is_file($checkpoint)) {
        $v = trim((string)@file_get_contents($checkpoint));
        if (ctype_digit($v)) return (int)$v;
    }
    return 0;
};

$startFromId = $fromIdArg > 0 ? $fromIdArg : $loadCheckpoint();
if ($startFromId > 0) {
    echo "resume_from_id: {$startFromId}\n";
}

$sendAlbum = function(array $files, string $caption) use ($http, $DISCORD_WEBHOOK): int {
    if (empty($files)) return 0;
    usort($files, fn($a, $b) => ($a['msg_id'] <=> $b['msg_id']));
    $files = array_slice($files, 0, 10);
    $multipart = [
        [
            'name'     => 'payload_json',
            'contents' => json_encode([
                'content' => $caption !== '' ? ("**Подпись:** " . $caption) : null,
            ], JSON_UNESCAPED_UNICODE),
        ],
    ];
    $i = 0;
    foreach ($files as $f) {
        $i++;
        $multipart[] = [
            'name'     => 'file' . $i,
            'filename' => $f['name'],
            'contents' => fopen($f['path'], 'rb'),
        ];
    }
    $http->post($DISCORD_WEBHOOK, ['multipart' => $multipart]);
    return count($files);
};

$sendSingle = function(Client $http, string $webhook, string $filename, string $path, string $caption): void {
    $http->post($webhook, [
        'multipart' => [
            [
                'name'     => 'payload_json',
                'contents' => json_encode([
                    'content' => $caption !== '' ? ("**Подпись:** " . $caption) : null,
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'name'     => 'file',
                'filename' => $filename,
                'contents' => fopen($path, 'rb'),
            ],
        ],
    ]);
};

$lastIdProcessed = $startFromId;

do {
    $batch = $mp->messages->getHistory([
        'peer'        => $peer,
        'offset_id'   => 0,
        'offset_date' => 0,
        'add_offset'  => 0,
        'limit'       => 100,
        'max_id'      => 0,
        'min_id'      => $lastIdProcessed,
        'hash'        => 0,
    ]);
    if (empty($batch['messages'])) { break; }

    usort($batch['messages'], fn($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
    $maxIdInBatch = $lastIdProcessed;

    foreach ($batch['messages'] as $m) {
        if (!isset($m['id'])) continue;
        $msgId = (int)$m['id'];
        if ($msgId <= $lastIdProcessed) continue;
        if ($msgId > $maxIdInBatch) $maxIdInBatch = $msgId;

        $date = isset($m['date']) ? (int)$m['date'] : 0;
        if ($sinceTs && $date && $date < $sinceTs) continue;

        $hasPhoto = isset($m['media']['_']) && $m['media']['_'] === 'messageMediaPhoto';
        $hasDocImage = (
            isset($m['media']['_']) && $m['media']['_'] === 'messageMediaDocument' &&
            isset($m['media']['document']['mime_type']) &&
            str_starts_with((string)$m['media']['document']['mime_type'], 'image/')
        );
        if (!$hasPhoto && !$hasDocImage) continue;

        $caption = '';
        if (!empty($m['message']) && is_string($m['message'])) $caption = $m['message'];

        $groupedId = $m['grouped_id'] ?? null;

        try {
            $localPath = $mp->downloadToDir($m, $tmpDir);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Download error for msg {$msgId}: {$e->getMessage()}\n");
            continue;
        }
        if (!is_file($localPath)) continue;

        $size = filesize($localPath) ?: 0;
        if ($size <= 0) { @unlink($localPath); continue; }
        if ($size > $maxBytes) { fwrite(STDERR, "Skip msg {$msgId} — {$size} bytes > {$maxBytes}\n"); @unlink($localPath); continue; }

        $filename = basename($localPath);

        if ($groupedId !== null) {
            if ($pendingGroupId !== null && $pendingGroupId !== $groupedId) {
                if (!empty($pendingFiles)) {
                    try {
                        $sentFiles = $sendAlbum($pendingFiles, $pendingCaption);
                        $totalSentFiles += $sentFiles;
                        $totalSentGroups++;
                        $groupsInWindow++;
                        $maxMsgIdInGroup = max(array_column($pendingFiles, 'msg_id'));
                        $saveCheckpoint($maxMsgIdInGroup);
                        $throttle();
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "Discord album error (group {$pendingGroupId}): {$e->getMessage()}\n");
                    }
                    foreach ($pendingFiles as $pf) { @unlink($pf['path']); }
                }
                $pendingFiles   = [];
                $pendingCaption = '';
            }
            $pendingGroupId = $groupedId;
            $pendingFiles[] = ['path' => $localPath, 'name' => $filename, 'msg_id' => $msgId];
            if ($pendingCaption === '' && $caption !== '') $pendingCaption = $caption;
            continue;
        }

        if ($pendingGroupId !== null && !empty($pendingFiles)) {
            try {
                $sentFiles = $sendAlbum($pendingFiles, $pendingCaption);
                $totalSentFiles += $sentFiles;
                $totalSentGroups++;
                $groupsInWindow++;
                $maxMsgIdInGroup = max(array_column($pendingFiles, 'msg_id'));
                $saveCheckpoint($maxMsgIdInGroup);
                $throttle();
            } catch (\Throwable $e) {
                fwrite(STDERR, "Discord album error (group {$pendingGroupId}): {$e->getMessage()}\n");
            }
            foreach ($pendingFiles as $pf) { @unlink($pf['path']); }
            $pendingGroupId = null;
            $pendingFiles   = [];
            $pendingCaption = '';
        }

        try {
            $sendSingle($http, $DISCORD_WEBHOOK, $filename, $localPath, $caption);
            $totalSentFiles++;
            $totalSentGroups++;
            $groupsInWindow++;
            $saveCheckpoint($msgId);
            $throttle();
        } catch (GuzzleException $e) {
            fwrite(STDERR, "Discord error for msg {$msgId}: {$e->getMessage()}\n");
        } finally {
            @unlink($localPath);
        }

        if ($limitArg > 0 && $totalSentFiles >= $limitArg) break;
        usleep(150000);
    }

    if ($limitArg > 0 && $totalSentFiles >= $limitArg) break;
    if ($maxIdInBatch <= $lastIdProcessed) break;
    $lastIdProcessed = $maxIdInBatch;
} while (true);

if ($pendingGroupId !== null && !empty($pendingFiles)) {
    try {
        $sentFiles = $sendAlbum($pendingFiles, $pendingCaption);
        $totalSentFiles += $sentFiles;
        $totalSentGroups++;
        $groupsInWindow++;
        $maxMsgIdInGroup = max(array_column($pendingFiles, 'msg_id'));
        $saveCheckpoint($maxMsgIdInGroup);
        $throttle();
    } catch (\Throwable $e) {
        fwrite(STDERR, "Discord album final flush error (group {$pendingGroupId}): {$e->getMessage()}\n");
    }
    foreach ($pendingFiles as $pf) { @unlink($pf['path']); }
}

echo "Done. Sent files: {$totalSentFiles}; groups: {$totalSentGroups}\n";