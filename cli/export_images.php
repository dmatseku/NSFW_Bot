<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Revolt\EventLoop;
use Amp\SignalException;

function loadEnv(): array
{
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
    $cfg = [
        'API_ID'            => (int)($_ENV['TELEGRAM_API_ID'] ?? 0),
        'API_HASH'          => (string)($_ENV['TELEGRAM_API_HASH'] ?? ''),
        'DISCORD_WEBHOOK'   => (string)($_ENV['DISCORD_WEBHOOK_URL'] ?? ''),
        'DISCORD_MAX_MB'    => (int)($_ENV['DISCORD_MAX_MB'] ?? 8),
        'GROUPS_PER_PERIOD' => (int)($_ENV['EXPORT_GROUPS_PER_PERIOD'] ?? 200),
        'PERIOD_SEC'        => (int)($_ENV['EXPORT_PERIOD_SEC'] ?? 86400),
    ];
    if (!$cfg['API_ID'] || $cfg['API_HASH'] === '' || $cfg['DISCORD_WEBHOOK'] === '') {
        fwrite(STDERR, "ENV error: set TELEGRAM_API_ID, TELEGRAM_API_HASH, DISCORD_WEBHOOK_URL\n");
        exit(1);
    }
    return $cfg;
}

function parseOptions(): array
{
    $opts = getopt('', ['channel::', 'since::', 'limit::', 'from-id::', 'checkpoint::']);
    $channel   = $opts['channel'] ?? ($_ENV['EXPORT_CHANNEL'] ?? null);
    $sinceArg  = $opts['since'] ?? null;
    $limit     = isset($opts['limit']) ? (int)$opts['limit'] : 0;
    $fromId    = isset($opts['from-id']) ? (int)$opts['from-id'] : 0;
    $checkpoint= $opts['checkpoint'] ?? (__DIR__ . '/../var/export_last_id.txt');
    if (!$channel) {
        fwrite(STDERR, "Usage: php cli/export_images.php --channel=@username [--since=2025-01-01] [--from-id=123456] [--limit=1000] [--checkpoint=/path]\n");
        exit(1);
    }
    $sinceTs = 0;
    if ($sinceArg) {
        $sinceTs = ctype_digit($sinceArg) ? (int)$sinceArg : (int)strtotime($sinceArg);
        if ($sinceTs <= 0) $sinceTs = 0;
    }
    return [
        'channel'    => $channel,
        'sinceTs'    => $sinceTs,
        'limit'      => $limit,
        'fromId'     => $fromId,
        'checkpoint' => $checkpoint,
    ];
}

function ensureDirs(string $varDir, string $tmpDir): void
{
    if (!is_dir($varDir)) @mkdir($varDir, 0750, true);
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0750, true);
}

function initMadeline(string $sessionFile, int $apiId, string $apiHash): \danog\MadelineProto\API
{
    $settings = new \danog\MadelineProto\Settings\AppInfo();
    $settings->setApiId($apiId);
    $settings->setApiHash($apiHash);
    $mp = new \danog\MadelineProto\API($sessionFile, $settings);
    $mp->start();
    return $mp;
}

function resolvePeer(\danog\MadelineProto\API $mp, string $channel)
{
    try {
        return $mp->getId($channel);
    } catch (\Throwable $e) {
        try {
            $info = $mp->getInfo($channel);
            if (isset($info['bot_api_id'])) return $info['bot_api_id'];
            if (isset($info['id'])) return $info['id'];
        } catch (\Throwable $e2) {
        }
        if (str_starts_with($channel, '@')) {
            $u = ltrim($channel, '@');
            try {
                $mp->contacts->resolveUsername(['username' => $u]);
                $info = $mp->getInfo($channel);
                if (isset($info['bot_api_id'])) return $info['bot_api_id'];
                if (isset($info['id'])) return $info['id'];
            } catch (\Throwable $e3) {
            }
        }
        throw $e;
    }
}

function initHttp(int $timeoutSec = 30): Client
{
    return new Client(['timeout' => $timeoutSec]);
}

function registerSignalHandlers(bool &$terminate): void
{
    EventLoop::setErrorHandler(function (\Throwable $e) use (&$terminate): void {
        if ($e instanceof SignalException) { $terminate = true; return; }
        fwrite(STDERR, "Unhandled event-loop error: " . $e->getMessage() . "\n");
    });
    EventLoop::onSignal(SIGINT,  function () use (&$terminate) { $terminate = true; });
    EventLoop::onSignal(SIGTERM, function () use (&$terminate) { $terminate = true; });
}

function coop_sleep(int $seconds, bool &$terminate): bool
{
    $end = time() + $seconds;
    while (time() < $end) {
        if ($terminate) return false;
        usleep(200000);
    }
    return true;
}

function throttle(int $limitGroups, int $periodSec, int &$periodStart, int &$groupsInWindow, bool &$terminate): bool
{
    if ($terminate) return false;
    $now = time();
    $elapsed = $now - $periodStart;
    if ($elapsed >= $periodSec) {
        $periodStart = $now;
        $groupsInWindow = 0;
        return true;
    }
    if ($groupsInWindow >= $limitGroups) {
        $sleep = $periodSec - $elapsed;
        if ($sleep > 0) {
            if (!coop_sleep($sleep, $terminate)) return false;
        }
        $periodStart = time();
        $groupsInWindow = 0;
    }
    return true;
}

function saveCheckpoint(string $file, int $id): void
{
    @file_put_contents($file, (string)$id, LOCK_EX);
    echo "checkpoint_saved: {$id}\n";
}

function loadCheckpoint(string $file): int
{
    if (is_file($file)) {
        $v = trim((string)@file_get_contents($file));
        if ($v !== '' && ctype_digit($v)) return (int)$v;
    }
    return 0;
}

function sendAlbum(Client $http, string $webhook, array $files, string $caption): int
{
    if (empty($files)) return 0;
    usort($files, fn($a, $b) => ($a['msg_id'] <=> $b['msg_id']));
    $files = array_slice($files, 0, 10);
    $multipart = [[
        'name'     => 'payload_json',
        'contents' => json_encode(['content' => $caption !== '' ? ("**Подпись:** " . $caption) : null], JSON_UNESCAPED_UNICODE),
    ]];
    $i = 0;
    foreach ($files as $f) {
        $i++;
        $multipart[] = [
            'name'     => 'file' . $i,
            'filename' => $f['name'],
            'contents' => fopen($f['path'], 'rb'),
        ];
    }
    $http->post($webhook, ['multipart' => $multipart]);
    return count($files);
}

function sendSingle(Client $http, string $webhook, string $filename, string $path, string $caption): void
{
    $http->post($webhook, [
        'multipart' => [
            [
                'name'     => 'payload_json',
                'contents' => json_encode(['content' => $caption !== '' ? ("**Подпись:** " . $caption) : null], JSON_UNESCAPED_UNICODE),
            ],
            [
                'name'     => 'file',
                'filename' => $filename,
                'contents' => fopen($path, 'rb'),
            ],
        ],
    ]);
}

function findStartMinId(\danog\MadelineProto\API $mp, $peer, int $sinceTs = 0): int
{
    $maxId = 0;
    $globalOldest = PHP_INT_MAX;
    $sinceCandidate = PHP_INT_MAX;
    while (true) {
        $batch = $mp->messages->getHistory([
            'peer'        => $peer,
            'offset_id'   => 0,
            'offset_date' => 0,
            'add_offset'  => 0,
            'limit'       => 100,
            'max_id'      => $maxId,
            'min_id'      => 0,
            'hash'        => 0,
        ]);
        if (empty($batch['messages'])) break;
        $minIdInBatch = PHP_INT_MAX;
        $allOlderThanSince = true;
        foreach ($batch['messages'] as $m) {
            if (empty($m['id'])) continue;
            $id = (int)$m['id'];
            $minIdInBatch = min($minIdInBatch, $id);
            $globalOldest = min($globalOldest, $id);
            $date = (int)($m['date'] ?? 0);
            if ($sinceTs > 0) {
                if ($date >= $sinceTs) {
                    $sinceCandidate = min($sinceCandidate, $id);
                    $allOlderThanSince = false;
                }
            } else {
                $allOlderThanSince = false;
            }
        }
        if ($sinceTs > 0 && $allOlderThanSince) break;
        if ($minIdInBatch === PHP_INT_MAX || $minIdInBatch <= 1) break;
        $maxId = $minIdInBatch - 1;
        if (count($batch['messages']) < 100) break;
    }
    if ($sinceTs > 0 && $sinceCandidate !== PHP_INT_MAX) return $sinceCandidate - 1;
    if ($globalOldest !== PHP_INT_MAX) return $globalOldest - 1;
    return 0;
}

function exportHistory(
    \danog\MadelineProto\API $mp,
    Client $http,
                             $peer,
    string $tmpDir,
    string $webhook,
    int $maxBytes,
    int $sinceTs,
    int $limitFiles,
    int $groupsPerPeriod,
    int $periodSec,
    string $checkpointFile,
    int $startFromId,
    bool &$terminate
): array {
    $periodStart = time();
    $groupsInWindow = 0;
    $totalSentFiles = 0;
    $totalSentGroups = 0;
    $pendingGroupId = null;
    $pendingFiles   = [];
    $pendingCaption = '';
    $lastIdProcessed = $startFromId;
    do {
        if ($terminate) break;
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
        if (empty($batch['messages'])) break;
        usort($batch['messages'], fn($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        $maxIdInBatch = $lastIdProcessed;
        foreach ($batch['messages'] as $m) {
            if ($terminate) break 2;
            if (!isset($m['id'])) continue;
            $msgId = (int)$m['id'];
            if ($msgId <= $lastIdProcessed) continue;
            if ($msgId > $maxIdInBatch) $maxIdInBatch = $msgId;
            $date = (int)($m['date'] ?? 0);
            if ($sinceTs && $date && $date < $sinceTs) continue;
            $hasPhoto = isset($m['media']['_']) && $m['media']['_'] === 'messageMediaPhoto';
            $hasDocImage = isset($m['media']['_']) && $m['media']['_'] === 'messageMediaDocument'
                && isset($m['media']['document']['mime_type'])
                && str_starts_with((string)$m['media']['document']['mime_type'], 'image/');
            if (!$hasPhoto && !$hasDocImage) continue;
            $caption = is_string($m['message'] ?? null) ? $m['message'] : '';
            $groupedId = $m['grouped_id'] ?? null;
            try {
                $localPath = $mp->downloadToDir($m, $tmpDir);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Download error for msg {$msgId}: {$e->getMessage()}\n");
                continue;
            }
            if (!is_file($localPath)) continue;
            $size = filesize($localPath) ?: 0;
            if ($size <= 0 || $size > $maxBytes) { @unlink($localPath); continue; }
            $filename = basename($localPath);
            if ($groupedId !== null) {
                if ($pendingGroupId !== null && $pendingGroupId !== $groupedId && !empty($pendingFiles)) {
                    try {
                        $sentFiles = sendAlbum($http, $webhook, $pendingFiles, $pendingCaption);
                        $totalSentFiles += $sentFiles;
                        $totalSentGroups++;
                        $groupsInWindow++;
                        saveCheckpoint($checkpointFile, max(array_column($pendingFiles, 'msg_id')));
                        if (!throttle($groupsPerPeriod, $periodSec, $periodStart, $groupsInWindow, $terminate)) break 3;
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "Discord album error: {$e->getMessage()}\n");
                    }
                    foreach ($pendingFiles as $pf) @unlink($pf['path']);
                    $pendingFiles = [];
                    $pendingCaption = '';
                }
                $pendingGroupId = $groupedId;
                $pendingFiles[] = ['path' => $localPath, 'name' => $filename, 'msg_id' => $msgId];
                if ($pendingCaption === '' && $caption !== '') $pendingCaption = $caption;
                continue;
            }
            if ($pendingGroupId !== null && !empty($pendingFiles)) {
                try {
                    $sentFiles = sendAlbum($http, $webhook, $pendingFiles, $pendingCaption);
                    $totalSentFiles += $sentFiles;
                    $totalSentGroups++;
                    $groupsInWindow++;
                    saveCheckpoint($checkpointFile, max(array_column($pendingFiles, 'msg_id')));
                    if (!throttle($groupsPerPeriod, $periodSec, $periodStart, $groupsInWindow, $terminate)) break 3;
                } catch (\Throwable $e) {
                    fwrite(STDERR, "Discord album error: {$e->getMessage()}\n");
                }
                foreach ($pendingFiles as $pf) @unlink($pf['path']);
                $pendingGroupId = null;
                $pendingFiles = [];
                $pendingCaption = '';
            }
            try {
                sendSingle($http, $webhook, $filename, $localPath, $caption);
                $totalSentFiles++;
                $totalSentGroups++;
                $groupsInWindow++;
                saveCheckpoint($checkpointFile, $msgId);
                if (!throttle($groupsPerPeriod, $periodSec, $periodStart, $groupsInWindow, $terminate)) break 3;
            } catch (GuzzleException $e) {
                fwrite(STDERR, "Discord error for msg {$msgId}: {$e->getMessage()}\n");
            } finally {
                @unlink($localPath);
            }
            if ($limitFiles > 0 && $totalSentFiles >= $limitFiles) break 2;
            usleep(150000);
        }
        if ($limitFiles > 0 && $totalSentFiles >= $limitFiles) break;
        if ($maxIdInBatch <= $lastIdProcessed) break;
        $lastIdProcessed = $maxIdInBatch;
    } while (true);
    if (!$terminate && $pendingGroupId !== null && !empty($pendingFiles)) {
        try {
            $sentFiles = sendAlbum($http, $webhook, $pendingFiles, $pendingCaption);
            $totalSentFiles += $sentFiles;
            $totalSentGroups++;
            saveCheckpoint($checkpointFile, max(array_column($pendingFiles, 'msg_id')));
        } catch (\Throwable $e) {
            fwrite(STDERR, "Discord album final flush error: {$e->getMessage()}\n");
        }
        foreach ($pendingFiles as $pf) @unlink($pf['path']);
    }
    return [$totalSentFiles, $totalSentGroups];
}

function main(): void
{
    $terminate = false;
    registerSignalHandlers($terminate);
    $cfg = loadEnv();
    $opt = parseOptions();
    $varDir = __DIR__ . '/../var';
    $tmpDir = $varDir . '/tmp';
    ensureDirs($varDir, $tmpDir);
    $mp = initMadeline($varDir . '/madeline.session', $cfg['API_ID'], $cfg['API_HASH']);
    $peer = resolvePeer($mp, $opt['channel']);
    $http = initHttp(30);
    $maxBytes = $cfg['DISCORD_MAX_MB'] * 1024 * 1024;
    $autoStartMinId = 0;
    if ($opt['fromId'] <= 0 && !is_file($opt['checkpoint'])) {
        $autoStartMinId = findStartMinId($mp, $peer, $opt['sinceTs']);
        if ($autoStartMinId > 0) echo "autostart_from_min_id: {$autoStartMinId}\n";
    }
    $startFromId = $opt['fromId'] > 0 ? $opt['fromId'] : (loadCheckpoint($opt['checkpoint']) ?: $autoStartMinId);
    if ($startFromId > 0) echo "resume_from_id: {$startFromId}\n";
    [$files, $groups] = exportHistory(
        mp:              $mp,
        http:            $http,
        peer:            $peer,
        tmpDir:          $tmpDir,
        webhook:         $cfg['DISCORD_WEBHOOK'],
        maxBytes:        $maxBytes,
        sinceTs:         $opt['sinceTs'],
        limitFiles:      $opt['limit'],
        groupsPerPeriod: $cfg['GROUPS_PER_PERIOD'],
        periodSec:       $cfg['PERIOD_SEC'],
        checkpointFile:  $opt['checkpoint'],
        startFromId:     $startFromId,
        terminate:       $terminate
    );
    echo "Done. Sent files: {$files}; groups: {$groups}\n";
}

main();