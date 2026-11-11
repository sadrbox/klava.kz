<?php
// Общее для сбора и просмотра статистики: настройки, база SQLite, IP и геолокация, разбор User-Agent

const STATS_DEFAULTS = [
    'password' => '',          // пароль панели статистики; пока пустой — панель закрыта
    'db_path' => '',           // путь к базе; пустой — выбирается автоматически (см. stats_db_path)
    'timezone' => 'Asia/Almaty',
    'geo' => true,             // определять страну и город по IP через ip-api.com
    'retention_days' => 400,   // записи старше удаляются; 0 — хранить всё
];
const STATS_GEO_TTL = 30 * 86400;   // сколько хранить найденное местоположение IP

function stats_config(): array
{
    static $config = null;
    if ($config === null) {
        $local = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
        $config = array_merge(STATS_DEFAULTS, is_array($local) ? $local : []);
        date_default_timezone_set($config['timezone']);
    }
    return $config;
}

// В Plesk у сайта есть каталог private рядом с httpdocs — он не отдаётся по HTTP, база лежит там.
// Иначе — stats/data, закрытый .htaccess
function stats_db_path(): string
{
    $path = stats_config()['db_path'];
    if ($path) return $path;
    $private = dirname(__DIR__, 2) . '/private';
    if (is_dir($private) && is_writable($private)) return $private . '/klava-stats.sqlite';
    if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0750);
    return __DIR__ . '/data/stats.sqlite';
}

function stats_db(): PDO
{
    static $db = null;
    if ($db) return $db;

    $db = new PDO('sqlite:' . stats_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    // WAL: панель читает, пока страницы пишут
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    // Один просмотр = одна загрузка страницы; визит (session_id) объединяет просмотры с перерывами
    // меньше 30 минут; посетитель (visitor_id) — браузер, сохраняется между визитами
    $db->exec('
        CREATE TABLE IF NOT EXISTS views (
            id TEXT PRIMARY KEY,
            session_id TEXT NOT NULL,
            visitor_id TEXT NOT NULL,
            started_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            ip TEXT, country TEXT, country_code TEXT, region TEXT, city TEXT, isp TEXT,
            user_agent TEXT, browser TEXT, os TEXT, device TEXT, is_bot INTEGER NOT NULL DEFAULT 0,
            url TEXT, referrer TEXT, referrer_host TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT,
            language TEXT, languages TEXT, timezone TEXT, screen TEXT, viewport TEXT, dpr REAL,
            touch INTEGER, connection TEXT, theme TEXT, text_source TEXT, layout TEXT,
            total_ms INTEGER NOT NULL DEFAULT 0, visible_ms INTEGER NOT NULL DEFAULT 0,
            active_ms INTEGER NOT NULL DEFAULT 0, typing_ms INTEGER NOT NULL DEFAULT 0,
            keystrokes INTEGER NOT NULL DEFAULT 0, chars INTEGER NOT NULL DEFAULT 0,
            errors INTEGER NOT NULL DEFAULT 0, lines INTEGER NOT NULL DEFAULT 0,
            load_ms INTEGER
        );
        CREATE INDEX IF NOT EXISTS views_started ON views (started_at);
        CREATE INDEX IF NOT EXISTS views_updated ON views (updated_at);
        CREATE INDEX IF NOT EXISTS views_session ON views (session_id);
        CREATE INDEX IF NOT EXISTS views_visitor ON views (visitor_id, started_at);
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            view_id TEXT NOT NULL,
            at INTEGER NOT NULL,
            name TEXT NOT NULL,
            data TEXT
        );
        CREATE INDEX IF NOT EXISTS events_at ON events (at);
        CREATE TABLE IF NOT EXISTS ip_geo (
            ip TEXT PRIMARY KEY,
            country TEXT, country_code TEXT, region TEXT, city TEXT, isp TEXT,
            checked_at INTEGER NOT NULL
        );
    ');
    return $db;
}

function stats_is_public_ip(string $ip): bool
{
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// За обратным прокси (nginx перед Apache) REMOTE_ADDR может оказаться адресом самого прокси —
// тогда настоящий адрес берётся из его заголовков. Публичному REMOTE_ADDR заголовки не перебивают:
// их может подделать сам посетитель
function stats_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (stats_is_public_ip($ip)) return $ip;
    foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
        foreach (explode(',', $_SERVER[$header] ?? '') as $candidate) {
            $candidate = trim($candidate);
            if (stats_is_public_ip($candidate)) return $candidate;
        }
    }
    return $ip;
}

// Страна, регион, город и провайдер по IP. Ответы кешируются, так что сервис спрашивается
// не чаще раза в месяц на адрес (бесплатный лимит ip-api.com — 45 запросов в минуту)
function stats_geo(string $ip): ?array
{
    if (!stats_config()['geo'] || !stats_is_public_ip($ip)) return null;

    $db = stats_db();
    $stmt = $db->prepare('SELECT country, country_code, region, city, isp FROM ip_geo WHERE ip = ? AND checked_at > ?');
    $stmt->execute([$ip, time() - STATS_GEO_TTL]);
    $cached = $stmt->fetch();
    if ($cached) return $cached;

    $body = stats_http_get('http://ip-api.com/json/' . rawurlencode($ip)
        . '?lang=ru&fields=status,country,countryCode,regionName,city,isp', 3);
    $data = $body ? json_decode($body, true) : null;
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') return null;

    $geo = [
        'country' => stats_str($data['country'] ?? null, 80),
        'country_code' => stats_str($data['countryCode'] ?? null, 2),
        'region' => stats_str($data['regionName'] ?? null, 80),
        'city' => stats_str($data['city'] ?? null, 80),
        'isp' => stats_str($data['isp'] ?? null, 120),
    ];
    $db->prepare('INSERT OR REPLACE INTO ip_geo (ip, country, country_code, region, city, isp, checked_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ip, $geo['country'], $geo['country_code'], $geo['region'], $geo['city'], $geo['isp'], time()]);
    return $geo;
}

function stats_http_get(string $url, int $timeout): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        return $ok && is_string($body) ? $body : null;
    }
    if (!ini_get('allow_url_fopen')) return null;
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout]]));
    return $body === false ? null : $body;
}

// Браузер, система и тип устройства по User-Agent. Порядок проверок важен: строки Edge, Opera
// и Яндекс Браузера содержат «Chrome», а Chrome — «Safari»
function stats_parse_ua(string $ua, bool $touch): array
{
    $first = function (array $patterns) use ($ua) {
        foreach ($patterns as $name => $pattern) {
            if (preg_match('~' . $pattern . '~i', $ua)) return $name;
        }
        return 'Другой';
    };
    $browser = $first([
        'Яндекс Браузер' => 'YaBrowser', 'Edge' => 'Edg(e|A|iOS)?/', 'Opera' => 'OPR/|Opera',
        'Samsung Internet' => 'SamsungBrowser', 'Firefox' => 'Firefox|FxiOS', 'Chrome' => 'Chrome|CriOS',
        'Safari' => 'Safari',
    ]);
    $os = $first([
        'Windows' => 'Windows', 'Android' => 'Android', 'iOS' => 'iPhone|iPad|iPod', 'macOS' => 'Macintosh',
        'ChromeOS' => 'CrOS', 'Linux' => 'Linux',
    ]);
    // iPad с iPadOS 13+ представляется компьютером Mac — выдаёт его только сенсорный экран
    if ($os === 'macOS' && $touch) $os = 'iOS';

    if (preg_match('~iPad|Tablet~i', $ua) || ($os === 'Android' && !preg_match('~Mobile~i', $ua)) || ($os === 'iOS' && !preg_match('~iPhone|iPod~i', $ua))) {
        $device = 'Планшет';
    } elseif (preg_match('~Mobi|iPhone|iPod~i', $ua)) {
        $device = 'Телефон';
    } else {
        $device = 'Компьютер';
    }

    $bot = $ua === '' || (bool) preg_match('~bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|monitor|curl|wget|python|java/|go-http|phantom|selenium|puppeteer|playwright~i', $ua);
    return ['browser' => $browser, 'os' => $os, 'device' => $device, 'is_bot' => $bot ? 1 : 0];
}

// PDO по умолчанию передаёт параметры строками, а в SQLite строка всегда «больше» числа:
// MAX(200, '5') вернёт '5'. Числа передаём числами
function stats_execute(PDOStatement $stmt, array $params): void
{
    foreach ($params as $name => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue($name, $value, $type);
    }
    $stmt->execute();
}

function stats_str($value, int $max): ?string
{
    if (!is_string($value) && !is_numeric($value)) return null;
    $value = trim((string) $value);
    if ($value === '') return null;
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

function stats_int($value, int $max): int
{
    return is_numeric($value) ? max(0, min($max, (int) round((float) $value))) : 0;
}
