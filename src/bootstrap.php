<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use GuzzleHttp\Client;

// Подключаем автозагрузчик composer
require __DIR__ . '/../vendor/autoload.php';

// Загружаем .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Глобальные переменные окружения
$TELEGRAM_TOKEN      = $_ENV['TELEGRAM_TOKEN'] ?? '';
$DISCORD_WEBHOOK_URL = $_ENV['DISCORD_WEBHOOK_URL'] ?? '';
$WEBHOOK_SECRET      = $_ENV['WEBHOOK_SECRET'] ?? '';

// Инициализация общего HTTP-клиента
$http = new Client([
    'timeout' => 15.0,
]);