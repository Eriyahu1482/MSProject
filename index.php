<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

$config = require __DIR__ . '/config.php';

$dbCfg = $config['db'];
$mysqli = new mysqli($dbCfg['host'], $dbCfg['user'], $dbCfg['pass'], $dbCfg['name']);
if ($mysqli->connect_errno) {
    die('MySQL connect error: ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');
require_once __DIR__ . '/Model.php';
require_once __DIR__ . '/Order.php';
require_once __DIR__ . '/MoySklad.php';

$orders = new Order($mysqli);
$ms     = new MoySklad($config['ms']['token']);

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function csrf_check(): bool
{
    return hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '');
}

function flash(string $type, string $text): void
{
    $_SESSION['flash'][] = ['type' => $type, 'text' => $text];
}

$organizations = [];
$counterparties = [];
$ms_error = null;
try {
    $organizations = $ms->getOrganizations();
    $counterparties = $ms->getCounterparties();
} catch (Throwable $ex) {
    $ms_error = $ex->getMessage();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Неверный CSRF-токен, обновите страницу.';
    }

    $number = trim((string)($_POST['order_number'] ?? ''));
    $org    = trim((string)($_POST['organization'] ?? ''));
    $agent  = trim((string)($_POST['agent'] ?? ''));

    if ($number === '' || mb_strlen($number) > 50) {
        $errors[] = 'Номер заказа обязателен (до 50 символов).';
    }
    $validOrgs   = array_column($organizations, 'name');
    $validAgents = array_column($counterparties, 'name');

    if ($org === '' || !in_array($org, $validOrgs, true)) {
        $errors[] = 'Выберите организацию из списка.';
    }
    if ($agent === '' || !in_array($agent, $validAgents, true)) {
        $errors[] = 'Выберите агента из списка.';
    }

    if (!$errors && $orders->exists($number)) {
        $errors[] = 'Заказ с номером «' . $number . '» уже существует.';
    }

    $localId = 0;
    if (!$errors) {
        try {
            $localId = $orders->create($number, $org, $agent);
        } catch (RuntimeException $ex) {
            if ($ex->getCode() === 1062) {
                $errors[] = 'Заказ с таким номером уже существует.';
            } else {
                $errors[] = 'Ошибка БД: ' . $ex->getMessage();
            }
        }
    }

    if (!$errors) {
        try {
            $orgMeta   = $ms->findOrganization($org);
            $agentMeta = $ms->findOrCreateCounterparty($agent);

            $states = $ms->getStates();
            $state  = $states[0] ?? null;

            $msId = $ms->createOrder(
                    $number,
                    $orgMeta,
                    $agentMeta,
                    $state !== null ? ['meta' => $state['meta']] : null
            );

            $orders->setMsInfo($localId, $msId, $state['name'] ?? 'Новый');

            flash('ok', 'Заказ создан и выгружен в МойСклад.');
        } catch (Throwable $ex) {
            flash('warn', 'Заказ сохранён локально, но не выгружен: ' . $ex->getMessage());
        }

        header('Location: orders.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Новый заказ</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Стили для селектов, чтобы они выглядели как инпуты */
        select {
            display: block; width: 100%; margin-top: 6px; padding: 10px 12px;
            border: 1px solid #d0d4da; border-radius: 8px; font-size: 15px;
            background-color: #fff; color: #222;
        }
        select:focus {
            border-color: #2a6df4; outline: none;
        }
    </style>
</head>
<body>
<div class="wrap">
    <nav>
        <a href="index.php">Новый заказ</a> |
        <a href="orders.php">Список заказов</a>
    </nav>

    <div class="card">
        <h1>Новый заказ</h1>

        <!-- Ошибка загрузки списков из МойСклад -->
        <?php if ($ms_error !== null): ?>
            <div class="msg err">
                Не удалось загрузить списки из МойСклад: <?= e($ms_error) ?>
            </div>
        <?php endif; ?>

        <!-- Ошибки валидации формы -->
        <?php if ($errors): ?>
            <div class="msg err">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="index.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <label>Номер заказа
                <input type="text" name="order_number" required maxlength="50"
                       value="<?= e($_POST['order_number'] ?? '') ?>">
            </label>

            <label>Организация
                <select name="organization" required>
                    <option value="">-- Выберите организацию --</option>
                    <?php foreach ($organizations as $o): ?>
                        <option value="<?= e($o['name']) ?>" <?= (($_POST['organization'] ?? '') === $o['name']) ? 'selected' : '' ?>>
                            <?= e($o['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Агент
                <select name="agent" required>
                    <option value="">-- Выберите агента --</option>
                    <?php foreach ($counterparties as $c): ?>
                        <option value="<?= e($c['name']) ?>" <?= (($_POST['agent'] ?? '') === $c['name']) ? 'selected' : '' ?>>
                            <?= e($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button type="submit">Отправить</button>
        </form>
    </div>
</div>
</body>
</html>