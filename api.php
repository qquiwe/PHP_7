<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cache.php';
header('Content-Type: application/json; charset=utf-8');

$__requestStart = microtime(true);
CountingPDO::resetCounter();

// формує масив відповіді замість негайного echo+exit (щоб устигнути дописати заголовки профілювання перед виводом)
function apiResult(bool $success, $payload, int $statusCode): array
{
    return ['success' => $success, 'payload' => $payload, 'status' => $statusCode];
}

function resultSuccess($data, int $statusCode = 200): array
{
    return apiResult(true, $data, $statusCode);
}

function resultError(string $message, int $statusCode): array
{
    return apiResult(false, $message, $statusCode);
}

function getRequestBody(): array
{
    if (!empty($_POST)) {
        return $_POST;
    }
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

// базовий список - рівно 1 SQL-запит, без збагачення (базова лінія кроку 1) 
function listTransactionsPlain(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM transactions ORDER BY transaction_date DESC, id DESC');
    return resultSuccess($stmt->fetchAll());
}

// список зі сумою по категорії в кожному рядку (поле category_total)
function listTransactionsWithCategoryTotal(PDO $pdo, bool $naive): array
{
    $stmt = $pdo->query('SELECT * FROM transactions ORDER BY transaction_date DESC, id DESC');
    $rows = $stmt->fetchAll();

    if ($naive) {
        // ПОВІЛЬНО: N+1 - окремий запит суми по категорії для кожного рядка (навмисно залишено для навантажувального тестування "до оптимізації", кроки 1-3)
        foreach ($rows as &$row) {
            $sumStmt = $pdo->prepare('SELECT SUM(amount) AS total FROM transactions WHERE category = :category');
            $sumStmt->execute([':category' => $row['category']]);
            $row['category_total'] = (float) ($sumStmt->fetch()['total'] ?? 0);
        }
        unset($row);
    } else {
        // ШВИДКО: один запит GROUP BY замість запиту в циклі (крок 5)
        $totalsStmt = $pdo->query('SELECT category, SUM(amount) AS total FROM transactions GROUP BY category');
        $totals = $totalsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as &$row) {
            $row['category_total'] = (float) ($totals[$row['category']] ?? 0);
        }
        unset($row);
    }

    return resultSuccess($rows);
}

function getTransaction(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        return resultError("Операцію з id={$id} не знайдено", 404);
    }

    return resultSuccess($row);
}

function createTransaction(PDO $pdo): array
{
    $body = getRequestBody();

    $amount = $body['amount'] ?? null;
    $category = trim((string) ($body['category'] ?? ''));
    $date = trim((string) ($body['transaction_date'] ?? ''));

    $missing = [];
    if ($amount === null || $amount === '' || !is_numeric($amount)) {
        $missing[] = 'amount (число)';
    }
    if ($category === '') {
        $missing[] = 'category (рядок)';
    }
    if ($date === '' || !DateTime::createFromFormat('Y-m-d', $date)) {
        $missing[] = 'transaction_date (формат РРРР-ММ-ДД)';
    }

    if (!empty($missing)) {
        return resultError('Некоректні або відсутні поля: ' . implode(', ', $missing), 400);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO transactions (amount, category, transaction_date) VALUES (:amount, :category, :date)'
    );
    $stmt->execute([
        ':amount'   => (float) $amount,
        ':category' => $category,
        ':date'     => $date,
    ]);

    $newId = (int) $pdo->lastInsertId();
    $row = $pdo->prepare('SELECT * FROM transactions WHERE id = :id');
    $row->execute([':id' => $newId]);

    // дані змінилися - кеш балансу вже не актуальний
    invalidateCache('balance');

    return resultSuccess($row->fetch(), 201);
}

// доменна дія: поточний баланс - кешується на 45 секунд (крок 6) 
function getBalanceAction(PDO $pdo): array
{
    $result = cachedQuery($pdo, 'balance', 'SELECT SUM(amount) AS balance FROM transactions', 45);

    return resultSuccess([
        'balance' => (float) ($result['balance'] ?? 0),
        'cache'   => $result['_cache'] ?? 'miss', // 'hit' або 'miss' - зручно бачити під час ab-тесту
    ]);
}

// маршрутизація
$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? null;
$id = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null;
$withCategoryTotal = ($_GET['with_category_total'] ?? '') === '1';
$naive = ($_GET['naive'] ?? '') === '1';

if ($resource !== 'transactions') {
    $result = resultError('Невідомий ресурс. Підтримується лише resource=transactions', 404);
} elseif ($method === 'GET') {
    if ($id === null) {
        if ($withCategoryTotal) {
            $result = listTransactionsWithCategoryTotal($pdo, $naive);
        } else {
            $result = listTransactionsPlain($pdo);
        }
    } elseif (!ctype_digit((string) $id)) {
        $result = resultError('Параметр id має бути цілим додатним числом', 400);
    } else {
        $result = getTransaction($pdo, (int) $id);
    }
} elseif ($method === 'POST') {
    if ($action === 'balance') {
        $result = getBalanceAction($pdo);
    } elseif ($action === null) {
        $result = createTransaction($pdo);
    } else {
        $result = resultError("Невідома дія action={$action}", 400);
    }
} else {
    $result = resultError("Метод {$method} не підтримується для resource=transactions", 405);
}

// профілювання (крок 1) і вивід відповіді
$elapsedMs = (microtime(true) - $__requestStart) * 1000;
$peakMemoryMb = memory_get_peak_usage(true) / 1024 / 1024;
$queryCount = CountingPDO::$queryCount;

header('X-Elapsed-Ms: ' . round($elapsedMs, 2));
header('X-Peak-Memory-MB: ' . round($peakMemoryMb, 2));
header('X-Query-Count: ' . $queryCount);

error_log(sprintf(
    '[api.php] resource=%s method=%s elapsed=%.2fms memory=%.2fMB queries=%d',
    (string) $resource,
    $method,
    $elapsedMs,
    $peakMemoryMb,
    $queryCount
));

$body = $result['success']
    ? ['success' => true, 'data' => $result['payload']]
    : ['success' => false, 'error' => $result['payload']];

http_response_code($result['status']);
echo json_encode($body, JSON_UNESCAPED_UNICODE);
