<?php
// Приём данных от страницы. navigator.sendBeacon присылает JSON: идентификаторы просмотра, визита
// и посетителя, сведения о браузере, накопленные счётчики времени и набора и новые события.
// Счётчики приходят нарастающим итогом, поэтому потерянный или повторный запрос ничего не портит

require __DIR__ . '/lib.php';

const MAX_DAY_MS = 86400000;
const MAX_EVENTS = 20;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$data = json_decode((string) file_get_contents('php://input', false, null, 0, 32768), true);
$validId = function ($value) {
    return is_string($value) && preg_match('/^[a-z0-9-]{8,40}$/i', $value);
};
if (!is_array($data) || !$validId($data['v'] ?? null) || !$validId($data['s'] ?? null) || !$validId($data['u'] ?? null)) {
    http_response_code(400);
    exit;
}

stats_config();
$db = stats_db();
$now = time();
$info = is_array($data['info'] ?? null) ? $data['info'] : [];
$m = is_array($data['m'] ?? null) ? $data['m'] : [];
$total = stats_int($m['total'] ?? 0, MAX_DAY_MS);

$stmt = $db->prepare('SELECT 1 FROM views WHERE id = ?');
$stmt->execute([$data['v']]);
$isNew = !$stmt->fetchColumn();

if ($isNew) {
    $ip = stats_client_ip();
    $ua = stats_str($_SERVER['HTTP_USER_AGENT'] ?? '', 500) ?? '';
    $touch = !empty($info['touch']);
    $client = stats_parse_ua($ua, $touch);

    // Переходы внутри сайта (перезагрузка страницы) источником не считаются
    $referrer = stats_str($info['referrer'] ?? null, 500);
    $referrerHost = $referrer ? stats_str(parse_url($referrer, PHP_URL_HOST), 120) : null;
    if ($referrerHost && strcasecmp($referrerHost, $_SERVER['HTTP_HOST'] ?? '') === 0) $referrerHost = null;

    $db->prepare('INSERT OR IGNORE INTO views (
            id, session_id, visitor_id, started_at, updated_at, ip, user_agent, browser, os, device, is_bot,
            url, referrer, referrer_host, utm_source, utm_medium, utm_campaign,
            language, languages, timezone, screen, viewport, dpr, touch, connection
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $data['v'], $data['s'], $data['u'], $now - intdiv($total, 1000), $now, $ip, $ua,
            $client['browser'], $client['os'], $client['device'], $client['is_bot'],
            stats_str($info['url'] ?? null, 300), $referrer, $referrerHost,
            stats_str($info['utm_source'] ?? null, 100), stats_str($info['utm_medium'] ?? null, 100),
            stats_str($info['utm_campaign'] ?? null, 100),
            stats_str($info['language'] ?? null, 20), stats_str($info['languages'] ?? null, 100),
            stats_str($info['timezone'] ?? null, 60), stats_str($info['screen'] ?? null, 20),
            stats_str($info['viewport'] ?? null, 20),
            is_numeric($info['dpr'] ?? null) ? min(10, max(0, (float) $info['dpr'])) : null,
            $touch ? 1 : 0, stats_str($info['connection'] ?? null, 20),
        ]);
}

// Тема, источник текста и раскладка меняются по ходу просмотра — храним последние значения
stats_execute($db->prepare('UPDATE views SET
        updated_at = :now,
        total_ms = MAX(total_ms, :total), visible_ms = MAX(visible_ms, :visible),
        active_ms = MAX(active_ms, :active), typing_ms = MAX(typing_ms, :typing),
        keystrokes = MAX(keystrokes, :keys), chars = MAX(chars, :chars),
        errors = MAX(errors, :errors), lines = MAX(lines, :lines),
        load_ms = COALESCE(load_ms, :load),
        theme = COALESCE(:theme, theme), text_source = COALESCE(:source, text_source),
        layout = COALESCE(:layout, layout)
    WHERE id = :id'), [
        ':now' => $now,
        ':total' => $total,
        ':visible' => stats_int($m['visible'] ?? 0, MAX_DAY_MS),
        ':active' => stats_int($m['active'] ?? 0, MAX_DAY_MS),
        ':typing' => stats_int($m['typing'] ?? 0, MAX_DAY_MS),
        ':keys' => stats_int($m['keys'] ?? 0, 10000000),
        ':chars' => stats_int($m['chars'] ?? 0, 10000000),
        ':errors' => stats_int($m['errors'] ?? 0, 10000000),
        ':lines' => stats_int($m['lines'] ?? 0, 1000000),
        ':load' => is_numeric($m['load'] ?? null) ? stats_int($m['load'], 600000) : null,
        ':theme' => stats_str($info['theme'] ?? null, 20),
        ':source' => stats_str($info['source'] ?? null, 60),
        ':layout' => stats_str($info['layout'] ?? null, 10),
        ':id' => $data['v'],
    ]);

// Время события: момент отправки минус то, сколько прошло с события (t — мс от загрузки страницы)
$events = is_array($data['events'] ?? null) ? array_slice($data['events'], 0, MAX_EVENTS) : [];
$insertEvent = $db->prepare('INSERT INTO events (view_id, at, name, data) VALUES (?, ?, ?, ?)');
foreach ($events as $event) {
    $name = $event['name'] ?? null;
    if (!is_string($name) || !preg_match('/^[a-z_]{1,32}$/', $name)) continue;
    $at = $now - intdiv(max(0, $total - stats_int($event['t'] ?? $total, MAX_DAY_MS)), 1000);
    $details = isset($event['data']) ? json_encode($event['data'], JSON_UNESCAPED_UNICODE) : null;
    $insertEvent->execute([$data['v'], $at, $name, $details !== false && $details !== null ? substr($details, 0, 1000) : null]);
}

http_response_code(204);

if ($isNew) {
    // Ответ уже отправлен — страница не ждёт, пока сервис геолокации ответит
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    $geo = stats_geo($ip);
    if ($geo) {
        $db->prepare('UPDATE views SET country = ?, country_code = ?, region = ?, city = ?, isp = ? WHERE id = ?')
            ->execute([$geo['country'], $geo['country_code'], $geo['region'], $geo['city'], $geo['isp'], $data['v']]);
    }
}

// Изредка удаляем записи старше срока хранения
$days = (int) stats_config()['retention_days'];
if ($days > 0 && mt_rand(1, 200) === 1) {
    $cutoff = $now - $days * 86400;
    $db->prepare('DELETE FROM views WHERE started_at < ?')->execute([$cutoff]);
    $db->prepare('DELETE FROM events WHERE at < ?')->execute([$cutoff]);
    $db->prepare('DELETE FROM ip_geo WHERE checked_at < ?')->execute([$now - STATS_GEO_TTL]);
}
