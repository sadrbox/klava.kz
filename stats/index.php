<?php
// Панель статистики: сводка за период, посещаемость по дням (за сегодня — по часам),
// откуда и с чего заходят, события, ошибки и последние визиты. Вход по паролю из config.php

require __DIR__ . '/lib.php';

$config = stats_config();
session_name('klava_stats');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => !empty($_SERVER['HTTPS'])]);

if ((string) $config['password'] === '') {
    render_page('Статистика', '<p class="notice">Панель закрыта: задайте пароль в <code>stats/config.php</code>'
        . ' (образец — <code>stats/config.sample.php</code>).</p>');
    exit;
}
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}
$loginError = '';
if (isset($_POST['password'])) {
    if (hash_equals((string) $config['password'], (string) $_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        header('Location: ./');
        exit;
    }
    sleep(2);   // замедляем подбор пароля
    $loginError = '<p class="error">Неверный пароль</p>';
}
if (empty($_SESSION['auth'])) {
    render_page('Вход', '<form method="post" class="login">' . $loginError
        . '<label>Пароль <input type="password" name="password" autofocus required></label>'
        . '<button>Войти</button></form>');
    exit;
}

// ---------- Данные ----------

$db = stats_db();
$periods = [1 => 'Сегодня', 7 => '7 дней', 30 => '30 дней', 90 => '90 дней', 0 => 'Всё время'];
$days = (int) ($_GET['days'] ?? 7);
if (!isset($periods[$days])) $days = 7;
$from = $days ? strtotime('today -' . ($days - 1) . ' days') : 0;
$where = 'is_bot = 0 AND started_at >= :from';
$params = [':from' => $from];
$offset = (new DateTime())->getOffset();   // SQLite считает в UTC, а дни и часы нужны местные

function rows(string $sql, array $params = []): array
{
    $stmt = stats_db()->prepare($sql);
    stats_execute($stmt, $params);
    return $stmt->fetchAll();
}

function row(string $sql, array $params = []): array
{
    return rows($sql, $params)[0] ?? [];
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="klava-stats-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM — чтобы Excel распознал UTF-8
    $stmt = $db->prepare("SELECT * FROM views WHERE $where ORDER BY started_at");
    stats_execute($stmt, $params);
    $header = true;
    while ($view = $stmt->fetch()) {
        if ($header) fputcsv($out, array_keys($view), ';', '"', '');
        $header = false;
        $view['started_at'] = date('Y-m-d H:i:s', (int) $view['started_at']);
        $view['updated_at'] = date('Y-m-d H:i:s', (int) $view['updated_at']);
        fputcsv($out, $view, ';', '"', '');
    }
    exit;
}

$summary = row("SELECT COUNT(*) views, COUNT(DISTINCT session_id) sessions, COUNT(DISTINCT visitor_id) visitors,
    COALESCE(SUM(typing_ms), 0) typing, COALESCE(SUM(chars), 0) chars, COALESCE(SUM(errors), 0) errors,
    COALESCE(SUM(lines), 0) lines, AVG(NULLIF(load_ms, 0)) load
    FROM views WHERE $where", $params);
$newVisitors = (int) row('SELECT COUNT(*) n FROM (SELECT MIN(started_at) first FROM views WHERE is_bot = 0 GROUP BY visitor_id)
    WHERE first >= :from', $params)['n'];
$online = (int) row('SELECT COUNT(DISTINCT session_id) n FROM views WHERE is_bot = 0 AND updated_at >= :since', [':since' => time() - 120])['n'];
$bots = (int) row('SELECT COUNT(*) n FROM views WHERE is_bot = 1 AND started_at >= :from', $params)['n'];

// Время визита распределено неравномерно (много коротких и немного очень длинных),
// поэтому главная цифра — медиана, среднее — для сравнения
$sessionTimes = rows("SELECT SUM(visible_ms) visible, SUM(active_ms) active FROM views WHERE $where GROUP BY session_id", $params);
$visibleTimes = array_map('intval', array_column($sessionTimes, 'visible'));
$activeTimes = array_map('intval', array_column($sessionTimes, 'active'));
$bounces = count(array_filter($activeTimes, function ($ms) { return $ms < 10000; }));

function median(array $values): int
{
    if (!$values) return 0;
    sort($values);
    $mid = intdiv(count($values), 2);
    return count($values) % 2 ? $values[$mid] : intdiv($values[$mid - 1] + $values[$mid], 2);
}

function average(array $values): int
{
    return $values ? intdiv(array_sum($values), count($values)) : 0;
}

// Посещаемость по дням или, за сегодня, по часам; пустые дни и часы тоже показываются
$byHour = $days === 1;
$bucket = $byHour ? "strftime('%H', started_at + :offset, 'unixepoch')" : "date(started_at + :offset, 'unixepoch')";
$buckets = [];
foreach (rows("SELECT $bucket bucket, COUNT(DISTINCT visitor_id) visitors, COUNT(DISTINCT session_id) sessions,
    SUM(active_ms) active FROM views WHERE $where GROUP BY bucket", $params + [':offset' => $offset]) as $r) {
    $buckets[$r['bucket']] = $r;
}
$timeline = [];
if ($byHour) {
    for ($h = 0; $h <= (int) date('G'); $h++) $timeline[sprintf('%02d', $h)] = sprintf('%02d:00', $h);
} elseif ($buckets || $days) {
    $start = $days ? $from : strtotime(min(array_keys($buckets)));
    for ($t = $start; $t <= time(); $t = strtotime('+1 day', $t)) $timeline[date('Y-m-d', $t)] = format_day($t);
}

$tops = [
    'Страны' => "COALESCE(country, 'Не определена')",
    'Города' => "COALESCE(city || ', ' || country, country, 'Не определён')",
    'Откуда пришли' => "COALESCE('utm: ' || utm_source, referrer_host, 'Прямой заход')",
    'Устройства' => 'device',
    'Браузеры' => 'browser',
    'Системы' => 'os',
    'Экраны' => 'screen',
    'Языки браузера' => 'language',
    'Провайдеры' => "COALESCE(isp, 'Не определён')",
    'Источник текста' => "CASE WHEN text_source = 'custom' THEN 'Свой текст'
        WHEN text_source = 'default' OR text_source IS NULL THEN 'Текст по умолчанию'
        WHEN text_source LIKE 'rss:%' THEN 'Статьи: ' || substr(text_source, 5) ELSE text_source END",
    'Тема' => "CASE theme WHEN 'dark' THEN 'Тёмная' WHEN 'light' THEN 'Светлая' ELSE theme END",
];
$topData = [];
foreach ($tops as $title => $expr) {
    $topData[$title] = rows("SELECT $expr label, COUNT(DISTINCT session_id) n FROM views WHERE $where
        GROUP BY label ORDER BY n DESC LIMIT 10", $params);
}

const EVENT_NAMES = [
    'text' => 'Выбран текст для набора',
    'restart' => 'Начать заново',
    'theme' => 'Смена темы',
    'keyboard' => 'Настройки клавиатуры',
    'explanations' => 'Загружен словарь пояснений',
    'feed_error' => 'Не загрузились статьи',
    'js_error' => 'Ошибка JavaScript',
];
$events = rows('SELECT e.name label, COUNT(*) n FROM events e JOIN views v ON v.id = e.view_id
    WHERE v.is_bot = 0 AND e.at >= :from GROUP BY e.name ORDER BY n DESC', $params);
foreach ($events as &$event) $event['label'] = EVENT_NAMES[$event['label']] ?? $event['label'];
unset($event);
$problems = rows("SELECT e.at, e.name, e.data, v.browser, v.os FROM events e JOIN views v ON v.id = e.view_id
    WHERE v.is_bot = 0 AND e.at >= :from AND e.name IN ('js_error', 'feed_error') ORDER BY e.at DESC LIMIT 10", $params);

$visits = rows("SELECT session_id, MIN(started_at) started, COUNT(*) views,
    SUM(visible_ms) visible, SUM(active_ms) active, SUM(typing_ms) typing, SUM(chars) chars, SUM(errors) errors,
    MAX(ip) ip, MAX(country) country, MAX(region) region, MAX(city) city, MAX(isp) isp,
    MAX(browser) browser, MAX(os) os, MAX(device) device, MAX(screen) screen,
    MAX(referrer_host) referrer, MAX(utm_source) utm,
    (SELECT MIN(p.started_at) FROM views p WHERE p.visitor_id = views.visitor_id) first_seen
    FROM views WHERE $where GROUP BY session_id ORDER BY started DESC LIMIT 50", $params);

// ---------- Форматирование ----------

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function num($n): string
{
    return number_format((float) $n, 0, ',', ' ');
}

function duration($ms): string
{
    $s = intdiv((int) $ms, 1000);
    if ($s < 60) return $s . ' с';
    if ($s < 3600) return intdiv($s, 60) . ' мин ' . sprintf('%02d', $s % 60) . ' с';
    return intdiv($s, 3600) . ' ч ' . sprintf('%02d', intdiv($s % 3600, 60)) . ' мин';
}

function percent($part, $whole, int $digits = 0): string
{
    return $whole ? number_format($part / $whole * 100, $digits, ',', ' ') . '%' : '—';
}

function speed($chars, $typingMs): string
{
    return $typingMs > 0 && $chars > 0 ? num($chars / ($typingMs / 60000)) . ' зн/мин' : '—';
}

function format_day(int $time): string
{
    $months = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    return date('j', $time) . ' ' . $months[(int) date('n', $time) - 1];
}

function format_time(int $time): string
{
    return (date('Y-m-d', $time) === date('Y-m-d') ? 'сегодня' : format_day($time)) . ' ' . date('H:i', $time);
}

// Данные события — JSON-объект; показываем только значения: «tengrinews · HTTP 429»
function event_details($json): string
{
    $data = json_decode((string) $json, true);
    if (!is_array($data)) return (string) $json;
    return implode(' · ', array_map(function ($value) {
        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }, array_filter($data, function ($value) { return $value !== null && $value !== ''; })));
}

function tile(string $label, string $value, string $note = ''): string
{
    return '<div class="tile"><div class="tile-label">' . h($label) . '</div><div class="tile-value">' . h($value) . '</div>'
        . ($note !== '' ? '<div class="tile-note">' . h($note) . '</div>' : '') . '</div>';
}

// Рейтинг: подпись с тонкой полосой доли, число и процент от всех визитов
function top_table(string $title, array $items, int $total, string $unit = 'визитов'): string
{
    $html = '<section class="card"><h2>' . h($title) . '</h2>';
    if (!$items) return $html . '<p class="empty">Нет данных</p></section>';
    $max = max(array_map(function ($r) { return (int) $r['n']; }, $items));
    $html .= '<table class="top"><tbody>';
    foreach ($items as $r) {
        $n = (int) $r['n'];
        $width = $max ? max(1, round($n / $max * 100)) : 0;
        $hint = num($n) . ' ' . $unit . ($total ? ' · ' . percent($n, $total, 1) : '');
        $html .= '<tr title="' . h($hint) . '"><td class="top-label"><span class="top-text">' . h($r['label'] ?? '—') . '</span>'
            . '<span class="top-bar" style="width:' . $width . '%"></span></td>'
            . '<td class="num">' . num($n) . '</td>'
            . ($total ? '<td class="num muted">' . percent($n, $total) . '</td>' : '') . '</tr>';
    }
    return $html . '</tbody></table></section>';
}

function render_page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Robots-Tag: noindex');
    $css = file_get_contents(__DIR__ . '/panel.css');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . h($title) . ' — klava.kz</title><style>' . $css . '</style></head><body><main>' . $body . '</main></body></html>';
}

// ---------- Страница ----------

$sessions = (int) $summary['sessions'];
ob_start();
?>
<header class="head">
  <h1>Статистика klava.kz</h1>
  <nav class="periods">
    <?php foreach ($periods as $value => $label): ?>
      <a href="?days=<?= $value ?>"<?= $value === $days ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach ?>
  </nav>
  <div class="actions">
    <a href="?days=<?= $days ?>&amp;export=csv">Скачать CSV</a>
    <a href="?logout=1">Выйти</a>
  </div>
</header>

<section class="tiles">
  <?= tile('Сейчас на сайте', num($online), 'активны за последние 2 мин') ?>
  <?= tile('Посетители', num($summary['visitors']), 'новых: ' . num($newVisitors)) ?>
  <?= tile('Визиты', num($sessions), 'просмотров: ' . num($summary['views'])) ?>
  <?= tile('Время на сайте', duration(median($visibleTimes)), 'медиана · среднее ' . duration(average($visibleTimes))) ?>
  <?= tile('Активное время', duration(median($activeTimes)), 'медиана · среднее ' . duration(average($activeTimes))) ?>
  <?= tile('Отказы', percent($bounces, $sessions), 'активны меньше 10 с') ?>
  <?= tile('Набрано знаков', num($summary['chars']), 'строк: ' . num($summary['lines'])) ?>
  <?= tile('Скорость набора', speed((int) $summary['chars'], (int) $summary['typing']), 'ошибок: ' . percent((int) $summary['errors'], (int) $summary['chars'], 2)) ?>
  <?= tile('Загрузка страницы', $summary['load'] ? number_format($summary['load'] / 1000, 1, ',', ' ') . ' с' : '—', 'в среднем') ?>
</section>

<?php if ($timeline):
  $maxVisitors = max(1, ...array_map(function ($key) use ($buckets) { return (int) ($buckets[$key]['visitors'] ?? 0); }, array_keys($timeline)));
?>
<section class="card">
  <h2>Посетители по <?= $byHour ? 'часам' : 'дням' ?></h2>
  <div class="chart">
    <div class="chart-max"><?= num($maxVisitors) ?></div>
    <div class="bars" style="--count: <?= count($timeline) ?>">
      <?php foreach ($timeline as $key => $label):
        $b = $buckets[$key] ?? ['visitors' => 0, 'sessions' => 0, 'active' => 0]; ?>
        <div class="bar-slot" tabindex="0">
          <div class="bar" style="height: <?= round((int) $b['visitors'] / $maxVisitors * 100, 1) ?>%"></div>
          <div class="tip"><b><?= h($label) ?></b><br>Посетители: <?= num($b['visitors']) ?><br>Визиты: <?= num($b['sessions']) ?><br>Активно: <?= duration($b['active']) ?></div>
        </div>
      <?php endforeach ?>
    </div>
    <div class="chart-dates"><span><?= h(reset($timeline)) ?></span><span><?= h(end($timeline)) ?></span></div>
  </div>
  <details>
    <summary>Таблицей</summary>
    <div class="scroll">
      <table class="list">
        <thead><tr><th><?= $byHour ? 'Час' : 'День' ?></th><th class="num">Посетители</th><th class="num">Визиты</th><th class="num">Активно</th></tr></thead>
        <tbody>
          <?php foreach (array_reverse($timeline, true) as $key => $label): $b = $buckets[$key] ?? null; ?>
            <tr><td><?= h($label) ?></td><td class="num"><?= num($b['visitors'] ?? 0) ?></td><td class="num"><?= num($b['sessions'] ?? 0) ?></td><td class="num"><?= duration($b['active'] ?? 0) ?></td></tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </details>
</section>
<?php endif ?>

<div class="grid">
  <?php foreach ($topData as $title => $items) echo top_table($title, $items, $sessions) ?>
  <?= top_table('События', $events, 0, 'раз') ?>
</div>

<?php if ($problems): ?>
<section class="card">
  <h2>Ошибки</h2>
  <div class="scroll">
    <table class="list">
      <thead><tr><th>Когда</th><th>Что</th><th>Подробности</th><th>Браузер</th></tr></thead>
      <tbody>
        <?php foreach ($problems as $p): ?>
          <tr><td class="nowrap"><?= h(format_time((int) $p['at'])) ?></td><td><?= h(EVENT_NAMES[$p['name']] ?? $p['name']) ?></td>
            <td class="details"><?= h(event_details($p['data'])) ?></td><td class="nowrap"><?= h($p['browser'] . ', ' . $p['os']) ?></td></tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif ?>

<section class="card">
  <h2>Последние визиты</h2>
  <?php if (!$visits): ?>
    <p class="empty">Визитов за этот период нет</p>
  <?php else: ?>
  <div class="scroll">
    <table class="list">
      <thead><tr>
        <th>Начало</th><th>IP</th><th>Откуда</th><th>Устройство</th><th>Пришёл с</th>
        <th class="num">На сайте</th><th class="num">Активно</th><th class="num">Набрано</th><th class="num">Скорость</th><th>Посетитель</th>
      </tr></thead>
      <tbody>
        <?php foreach ($visits as $v):
          $place = implode(', ', array_filter([$v['city'], $v['region'] !== $v['city'] ? $v['region'] : null, $v['country']])); ?>
          <tr>
            <td class="nowrap"><?= h(format_time((int) $v['started'])) ?></td>
            <td class="mono" title="<?= h($v['isp'] ?? '') ?>"><?= h($v['ip']) ?></td>
            <td><?= h($place ?: '—') ?></td>
            <td class="nowrap" title="<?= h($v['screen'] ?? '') ?>"><?= h($v['device'] . ' · ' . $v['browser'] . ', ' . $v['os']) ?></td>
            <td><?= h($v['utm'] ? 'utm: ' . $v['utm'] : ($v['referrer'] ?: 'прямой заход')) ?></td>
            <td class="num"><?= duration($v['visible']) ?></td>
            <td class="num"><?= duration($v['active']) ?></td>
            <td class="num"><?= num($v['chars']) ?></td>
            <td class="num nowrap"><?= speed((int) $v['chars'], (int) $v['typing']) ?></td>
            <td><?= (int) $v['first_seen'] < (int) $v['started'] ? 'вернулся' : 'новый' ?><?= (int) $v['views'] > 1 ? ' · просмотров: ' . (int) $v['views'] : '' ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>
</section>

<?php if ($bots): ?>
  <p class="footnote">Ботов и автоматических проверок за период: <?= num($bots) ?> — в статистику не включены.</p>
<?php endif ?>
<?php
render_page('Статистика', ob_get_clean());
