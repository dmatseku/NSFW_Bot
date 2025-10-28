<?php
declare(strict_types=1);

namespace src\telegram;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class TelegramRelay
{
    private string $telegramToken;
    private string $discordWebhookUrl;
    private Client $http;

    private string $storeBaseDir;
    private int $groupFlushAfterSec;

    public function __construct(
        string $telegramToken,
        string $discordWebhookUrl,
        ?string $storeBaseDir = null,
        int $groupFlushAfterSec = 2
    ) {
        $this->telegramToken      = $telegramToken;
        $this->discordWebhookUrl  = $discordWebhookUrl;
        $this->http = new Client(['timeout' => 20.0]);

        $this->storeBaseDir = $storeBaseDir ?: \dirname(__DIR__, 2) . '/var/relay';
        $this->groupFlushAfterSec = max(1, $groupFlushAfterSec);

        if (!is_dir($this->storeBaseDir)) {
            @mkdir($this->storeBaseDir, 0750, true);
        }
        if (!is_dir($this->storeBaseDir . '/groups')) {
            @mkdir($this->storeBaseDir . '/groups', 0750, true);
        }
    }

    public function handleUpdate(array $update): void
    {
        $msg = $update['channel_post'] ?? $update['message'] ?? null;
        if (!$msg) {
            return;
        }

        $hasPhoto = isset($msg['photo']);
        $hasImageDocument = isset($msg['document']['mime_type'])
            && str_starts_with((string)$msg['document']['mime_type'], 'image/');

        if (!$hasPhoto && !$hasImageDocument) {
            $this->flushExpiredGroups();
            return;
        }

        $caption = $msg['caption'] ?? '';
        $fileId  = $this->extractBestFileId($msg);
        if (!$fileId) {
            $this->flushExpiredGroups();
            return;
        }

        $filePath = $this->getTelegramFilePath($fileId);
        if (!$filePath) {
            $this->flushExpiredGroups();
            return;
        }

        $binary = $this->downloadTelegramFile($filePath);
        if ($binary === null) {
            $this->flushExpiredGroups();
            return;
        }

        $filename = basename($filePath) ?: 'image.jpg';
        $groupId = $msg['media_group_id'] ?? null;

        if ($groupId !== null) {
            $this->saveGroupItem((string)$groupId, $binary, $filename, $caption, (int)($msg['message_id'] ?? 0));
            $this->flushExpiredGroups();
            return;
        }

        $this->sendDiscordSingle($binary, $filename, $caption);
        $this->flushExpiredGroups();
    }

    private function extractBestFileId(array $msg): ?string
    {
        if (isset($msg['photo']) && is_array($msg['photo'])) {
            $sizes = $msg['photo'];
            usort($sizes, fn($a, $b) => (($a['file_size'] ?? 0) <=> ($b['file_size'] ?? 0)));
            $best = end($sizes);
            return $best['file_id'] ?? null;
        }
        if (isset($msg['document']) && is_array($msg['document'])) {
            return $msg['document']['file_id'] ?? null;
        }
        return null;
    }

    private function getTelegramFilePath(string $fileId): ?string
    {
        try {
            $resp = $this->http->get("https://api.telegram.org/bot{$this->telegramToken}/getFile", [
                'query' => ['file_id' => $fileId],
            ]);
            $json = json_decode((string)$resp->getBody(), true);
            if (($json['ok'] ?? false) && isset($json['result']['file_path'])) {
                return $json['result']['file_path'];
            }
        } catch (GuzzleException $e) {
            error_log('getFile error: ' . $e->getMessage());
        }
        return null;
    }

    private function downloadTelegramFile(string $filePath): ?string
    {
        $url = "https://api.telegram.org/file/bot{$this->telegramToken}/{$filePath}";
        try {
            $resp = $this->http->get($url);
            return (string)$resp->getBody();
        } catch (GuzzleException $e) {
            error_log('download error: ' . $e->getMessage());
            return null;
        }
    }

    private function sendDiscordSingle(string $binary, string $filename, string $caption): void
    {
        $payload = [
            [
                'name'     => 'payload_json',
                'contents' => json_encode([
                    'content' => $caption !== '' ? ("**Подпись:** " . $caption) : null,
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'name'     => 'file',
                'filename' => $filename,
                'contents' => $binary,
            ],
        ];
        try {
            $this->http->post($this->discordWebhookUrl, ['multipart' => $payload]);
        } catch (GuzzleException $e) {
            error_log('discord error: ' . $e->getMessage());
        }
    }

    private function sendDiscordAlbum(array $items, string $caption): void
    {
        usort($items, fn($a, $b) => ($a['msg_id'] <=> $b['msg_id']));
        $items = array_slice($items, 0, 10);

        $multipart = [
            [
                'name'     => 'payload_json',
                'contents' => json_encode([
                    'content' => $caption !== '' ? ("**Подпись:** " . $caption) : null,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];
        $i = 0;
        foreach ($items as $it) {
            $i++;
            $multipart[] = [
                'name'     => 'file' . $i,
                'filename' => $it['name'],
                'contents' => fopen($it['path'], 'rb'),
            ];
        }

        try {
            $this->http->post($this->discordWebhookUrl, ['multipart' => $multipart]);
        } catch (GuzzleException $e) {
            error_log('discord error: ' . $e->getMessage());
        } finally {
            foreach ($items as $it) {
                @unlink($it['path']);
            }
        }
    }

    private function saveGroupItem(string $groupId, string $binary, string $filename, string $caption, int $msgId): void
    {
        $dir = $this->storeBaseDir . '/groups/' . preg_replace('~[^0-9A-Za-z_-]~', '_', $groupId);
        $lockFile = $dir . '/lock';
        $metaFile = $dir . '/meta.json';

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $fh = fopen($lockFile, 'c+');
        if ($fh === false) {
            error_log('group lock open failed: ' . $groupId);
            return;
        }
        try {
            flock($fh, LOCK_EX);

            $meta = [
                'created_at' => time(),
                'updated_at' => time(),
                'caption'    => $caption,
                'items'      => [],
            ];
            if (is_file($metaFile)) {
                $tmp = json_decode((string)file_get_contents($metaFile), true);
                if (is_array($tmp)) {
                    $meta = array_merge(['created_at' => time(), 'updated_at' => time(), 'caption' => $caption, 'items' => []], $tmp);
                    $meta['updated_at'] = time();
                    if ($meta['caption'] === '' && $caption !== '') {
                        $meta['caption'] = $caption;
                    }
                }
            }

            $uniq = sprintf('%d_%s', $msgId, bin2hex(random_bytes(4)));
            $path = $dir . '/' . $uniq . '_' . $filename;
            file_put_contents($path, $binary);

            $meta['items'][] = [
                'path'   => $path,
                'name'   => $filename,
                'msg_id' => $msgId,
            ];

            file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE));
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function flushExpiredGroups(): void
    {
        $groupsDir = $this->storeBaseDir . '/groups';
        $now = time();

        $list = @scandir($groupsDir) ?: [];
        foreach ($list as $name) {
            if ($name === '.' || $name === '..') continue;

            $dir = $groupsDir . '/' . $name;
            if (!is_dir($dir)) continue;

            $lockFile = $dir . '/lock';
            $metaFile = $dir . '/meta.json';

            $fh = fopen($lockFile, 'c+');
            if ($fh === false) continue;

            $locked = flock($fh, LOCK_EX | LOCK_NB);
            if (!$locked) { fclose($fh); continue; }

            try {
                if (!is_file($metaFile)) {
                    $this->rmDir($dir);
                    continue;
                }
                $meta = json_decode((string)file_get_contents($metaFile), true);
                if (!is_array($meta)) {
                    $this->rmDir($dir);
                    continue;
                }
                $updatedAt = (int)($meta['updated_at'] ?? 0);
                if ($updatedAt === 0) {
                    $this->rmDir($dir);
                    continue;
                }
                if (($now - $updatedAt) < $this->groupFlushAfterSec) {
                    continue;
                }

                $items = $meta['items'] ?? [];
                $caption = (string)($meta['caption'] ?? '');
                $items = array_values(array_filter($items, fn($it) => isset($it['path']) && is_file($it['path'])));

                if (!empty($items)) {
                    $this->sendDiscordAlbum($items, $caption);
                }

                $this->rmDir($dir);
            } finally {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = scandir($dir) ?: [];
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            if (is_dir($p)) $this->rmDir($p);
            else @unlink($p);
        }
        @rmdir($dir);
    }
}