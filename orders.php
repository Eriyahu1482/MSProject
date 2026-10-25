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

$ordersModel = new Order($mysqli);
$ms = new MoySklad($config['ms']['token']);

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$msgType = '';
$msgText = '';

/* ========== ОБРАБОТКА СМЕНЫ СТАТУСА (БЕЗ РЕДИРЕКТА) ========== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $orderId   = (int)($_POST['order_id'] ?? 0);
    $newStatus = trim((string)($_POST['status'] ?? ''));

    $order = $ordersModel->find($orderId);

    if (!$order) {
        $msgType = 'err';
        $msgText = "Заказ #{$orderId} не найден в БД.";
    } elseif ($newStatus === '') {
        $msgType = 'err';
        $msgText = 'Статус не выбран.';
    } elseif (empty($order->ms_id)) {
        $ordersModel->updateStatus($orderId, $newStatus);
        $msgType = 'warn';
        $msgText = "Заказ «{$order->order_number}» не выгружен в МС, статус обновлён только локально.";
    } else {
        try {
            // 1. Отправляем в МойСклад
            $ms->updateOrderState($order->ms_id, $newStatus);

            // 2. Если ошибки нет, обновляем локальную БД
            $ordersModel->updateStatus($orderId, $newStatus);

            $msgType = 'ok';
            $msgText = "Статус заказа «{$order->order_number}» успешно изменён на «{$newStatus}» в БД и в МойСклад.";
        } catch (Throwable $ex) {
            $msgType = 'err';
            $msgText = 'Ошибка обновления: ' . $ex->getMessage();
            error_log('[STATUS UPDATE] ' . $ex->getMessage());
        }
    }
}

/* ========== ПАГИНАЦИЯ ========== */
$limit = 3;
$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$total = $ordersModel->countAll();
$totalPages = (int)ceil($total / $limit);
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;
$orders = $ordersModel->getPaginated($limit, $offset);

/* ========== АВТОСИНХРОНИЗАЦИЯ ИЗ МОЙСКЛАД ========== */
// Подтягиваем актуальные статусы из МС, если их поменяли прямо в интерфейсе МС
$syncError = null;
foreach ($orders as $o) {
    if (empty($o->ms_id)) continue;
    try {
        $msState = $ms->getOrderStateName($o->ms_id);
        if ($msState !== null && $msState !== $o->status) {
            $ordersModel->updateStatus((int)$o->id, $msState);
            $o->status = $msState;
        }
    } catch (Throwable $ex) {
        $syncError = $ex->getMessage();
    }
}

/* ========== СТАТУСЫ ДЛЯ СПИСКА ========== */
$availableStates = [];
try {
    $states = $ms->getStates();
    $availableStates = array_column($states, 'name');
} catch (Throwable $ex) {
    if ($syncError === null) $syncError = $ex->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Список заказов</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; vertical-align: middle; }
        th { background: #f8f9fa; color: #555; font-weight: 600; }
        tr:hover td { background: #fdfdfd; }
        .status-form { display: flex; gap: 5px; margin: 0; }
        .status-form select { padding: 5px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        .status-form button { padding: 5px 10px; background: #2a6df4; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; }
        .status-form button:hover { background: #1a5ae0; }

        .pagination { margin-top: 24px; display: flex; justify-content: center; align-items: center; gap: 10px; }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 6px; font-size: 14px; text-decoration: none; }
        .pagination a { background: #f0f2f5; color: #2a6df4; }
        .pagination a:hover { background: #e0e5ec; }
        .pagination .disabled { color: #aaa; background: #f8f9fa; }
        .pagination .current { font-weight: 600; color: #333; }

        .empty-msg { text-align: center; padding: 30px; color: #777; }
        .msg { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .msg.ok  { background:#e7f7ec; color:#1c6b36; }
        .msg.err { background:#fdecec; color:#a12020; }
        .msg.warn{ background:#fff6e0; color:#8a5a00; }
    </style>
</head>
<body>
<div class="wrap">
    <nav>
        <a href="index.php">Новый заказ</a> |
        <a href="orders.php">Список заказов</a>
    </nav>

    <div class="card">
        <h1>Список заказов <span style="font-size:14px; color:#888; font-weight:normal;">(Всего: <?= $total ?>)</span></h1>

        <!-- Вывод сообщения о результате -->
        <?php if ($msgText): ?>
            <div class="msg <?= e($msgType) ?>"><?= e($msgText) ?></div>
        <?php endif; ?>

        <?php if ($syncError): ?>
            <div class="msg warn">Не удалось обновить данные из МС: <?= e($syncError) ?></div>
        <?php endif; ?>

        <?php if (empty($orders)): ?>
            <div class="empty-msg">Нет выгруженных заказов. <a href="index.php">Создайте первый заказ</a>.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Номер заказа</th>
                        <th>Организация</th>
                        <th>Агент</th>
                        <th>Статус (изменить)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><strong><?= e($order->order_number) ?></strong></td>
                            <td><?= e($order->organization) ?></td>
                            <td><?= e($order->agent) ?></td>
                            <td>
                                <form method="post" action="orders.php?page=<?= $page ?>" class="status-form">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="order_id" value="<?= (int)$order->id ?>">

                                    <select name="status" required>
                                        <?php if ($order->status && !in_array($order->status, $availableStates, true)): ?>
                                            <option value="<?= e($order->status) ?>" selected><?= e($order->status) ?></option>
                                        <?php endif; ?>

                                        <?php foreach ($availableStates as $st): ?>
                                            <option value="<?= e($st) ?>" <?= ($order->status === $st) ? 'selected' : '' ?>>
                                                <?= e($st) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="submit">ОК</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>">&laquo; Назад</a>
                    <?php else: ?>
                        <span class="disabled">&laquo; Назад</span>
                    <?php endif; ?>

                    <span class="current">Стр. <?= $page ?> из <?= $totalPages ?></span>

                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>">Вперед &raquo;</a>
                    <?php else: ?>
                        <span class="disabled">Вперед &raquo;</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
</body>
</html>