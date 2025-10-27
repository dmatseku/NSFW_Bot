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

    public function __construct(string $telegramToken, string $discordWebhookUrl)
    {
        $this->telegramToken      = $telegramToken;
        $this->discordWebhookUrl  = $discordWebhookUrl;
        $this->http = new Client([
            'timeout' => 20.0,
        ]);
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
            return;
        }

        $caption = $msg['caption'] ?? '';
        $fileId  = $this->extractBestFileId($msg);

        if (!$fileId) {
            return;
        }

        $filePath = $this->getTelegramFilePath($fileId);
        if (!$filePath) {
            return;
        }

        $binary = $this->downloadTelegramFile($filePath);
        if ($binary === null) {
            return;
        }

        $this->sendDiscord($binary, basename($filePath) ?: 'image.jpg', $caption);
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

    private function sendDiscord(string $binary, string $filename, string $caption): void
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
            $this->http->post($this->discordWebhookUrl, [
                'multipart' => $payload,
            ]);
        } catch (GuzzleException $e) {
            error_log('discord error: ' . $e->getMessage());
        }
    }
}