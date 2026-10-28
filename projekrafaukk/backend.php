<?php
declare(strict_types=1);

const IDLE_TIMEOUT = 900;

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function checkCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf']) ||
        !hash_equals($_SESSION['csrf'], $token)) {
        respond(['message' => 'Sesi halaman sudah berubah. Muat ulang halaman.'], 403);
    }
}

function endSession(): void
{
    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Strict',
    ]);
    session_destroy();
}

function requireLogin(): void
{
    if (!isset($_SESSION['admin_id'], $_SESSION['admin_username'])) {
        respond(['message' => 'Silakan login kembali.'], 401);
    }

    $_SESSION['last_activity'] = time();
}

function database(): PDO
{
    return new PDO(
        'mysql:host=127.0.0.1;dbname=crudukk_rafa;charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function requestData(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        respond(['message' => 'Data request tidak valid.'], 422);
    }

    return $data;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', (string) IDLE_TIMEOUT);

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (isset($_SESSION['admin_id']) &&
    time() - ($_SESSION['last_activity'] ?? 0) > IDLE_TIMEOUT) {
    endSession();
    session_start();
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($action === 'auth' && $method === 'GET') {
    $loggedIn = isset($_SESSION['admin_id'], $_SESSION['admin_username']);
    if ($loggedIn) {
        $_SESSION['last_activity'] = time();
    }

    respond([
        'authenticated' => $loggedIn,
        'username' => $loggedIn ? $_SESSION['admin_username'] : null,
        'csrfToken' => csrfToken(),
    ]);
}

if ($action === 'login' && $method === 'POST') {
    checkCsrf();
    $input = requestData();

    if (!is_string($input['username'] ?? null) ||
        !is_string($input['password'] ?? null) ||
        $input['username'] === '' || $input['password'] === '' ||
        strlen($input['username']) > 100) {
        respond(['message' => 'Username atau password tidak valid.'], 422);
    }

    try {
        $query = database()->prepare(
            'SELECT id, username, pw FROM admin WHERE username = ? LIMIT 1'
        );
        $query->execute([$input['username']]);
        $admin = $query->fetch();
    } catch (PDOException $error) {
        error_log('Database login error: ' . $error->getMessage());
        respond(['message' => 'Tidak dapat memproses login. Periksa koneksi database.'], 500);
    }

    if (!$admin || !is_string($admin['pw']) ||
        !hash_equals($admin['pw'], $input['password'])) {
        respond(['message' => 'Username atau password salah.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    respond(['username' => $admin['username'], 'csrfToken' => csrfToken()]);
}

if ($action === 'logout' && $method === 'POST') {
    requireLogin();
    checkCsrf();
    endSession();
    respond(['loggedOut' => true]);
}

$readActions = ['products', 'transactions', 'summary'];
$writeActions = [
    'add_product',
    'update_product',
    'delete_product',
    'add_transaction',
    'update_transaction',
    'delete_transaction',
];

if (in_array($action, $readActions, true) && $method === 'GET') {
    requireLogin();
    try {
        $db = database();
        if ($action === 'products') {
            $rows = $db->query(
                'SELECT id, name, category, stock, price FROM kopi ORDER BY name'
            )->fetchAll();
            respond(['data' => $rows]);
        }

        if ($action === 'transactions') {
            $rows = $db->query(
                'SELECT t.id, t.created_at, t.quantity, t.unit_price, t.total, t.status, ' .
                'b.name AS product_name FROM transaksi t ' .
                'JOIN kopi b ON b.id = t.product_id ORDER BY t.created_at DESC, t.id DESC'
            )->fetchAll();
            respond(['data' => $rows]);
        }

        $summary = $db->query(
            "SELECT COALESCE(SUM(total), 0) AS completed_revenue " .
            "FROM transaksi WHERE status = 'completed'"
        )->fetch();
        respond(['data' => $summary]);
    } catch (PDOException $error) {
        error_log('Dashboard read error: ' . $error->getMessage());
        respond(['message' => 'Tidak dapat memuat data. Pastikan tabel dashboard sudah dibuat.'], 500);
    }
}

if (in_array($action, $writeActions, true) && $method === 'POST') {
    requireLogin();
    checkCsrf();
    $input = requestData();

    try {
        $db = database();

        if ($action === 'add_product') {
            $name = $input['name'] ?? null;
            $category = $input['category'] ?? '';
            $stock = filter_var($input['stock'] ?? null, FILTER_VALIDATE_INT);
            $price = $input['price'] ?? null;

            if (!is_string($name) || trim($name) === '' || strlen($name) > 150 ||
                !is_string($category) || strlen($category) > 100 ||
                $stock === false || $stock < 0 ||
                (!is_int($price) && !is_float($price) && !is_string($price)) ||
                !is_numeric($price) || (float) $price < 0 || (float) $price > 999999999999.99) {
                respond(['message' => 'Periksa nama, kategori, stok, dan harga kopi.'], 422);
            }

            $insert = $db->prepare(
                'INSERT INTO kopi (name, category, stock, price) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([trim($name), trim($category), $stock, number_format((float) $price, 2, '.', '')]);
            respond(['message' => 'Kopi berhasil ditambahkan.'], 201);
        }

        if ($action === 'update_product' || $action === 'delete_product') {
            $productId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId < 1) {
                respond(['message' => 'ID kopi tidak valid.'], 422);
            }

            if ($action === 'update_product') {
                $name = $input['name'] ?? null;
                $category = $input['category'] ?? '';
                $stock = filter_var($input['stock'] ?? null, FILTER_VALIDATE_INT);
                $price = $input['price'] ?? null;

                if (!is_string($name) || trim($name) === '' || strlen($name) > 150 ||
                    !is_string($category) || strlen($category) > 100 ||
                    $stock === false || $stock < 0 ||
                    (!is_int($price) && !is_float($price) && !is_string($price)) ||
                    !is_numeric($price) || (float) $price < 0 ||
                    (float) $price > 999999999999.99) {
                    respond(['message' => 'Periksa nama, kategori, stok, dan harga kopi.'], 422);
                }

                $update = $db->prepare(
                    'UPDATE kopi SET name = ?, category = ?, stock = ?, price = ? WHERE id = ?'
                );
                $update->execute([
                    trim($name),
                    trim($category),
                    $stock,
                    number_format((float) $price, 2, '.', ''),
                    $productId,
                ]);
                if ($update->rowCount() === 0) {
                    $exists = $db->prepare('SELECT id FROM kopi WHERE id = ?');
                    $exists->execute([$productId]);
                    if (!$exists->fetch()) {
                        respond(['message' => 'Kopi tidak ditemukan.'], 404);
                    }
                }
                respond(['message' => 'Kopi berhasil diperbarui.']);
            }

            $references = $db->prepare('SELECT id FROM transaksi WHERE product_id = ? LIMIT 1');
            $references->execute([$productId]);
            if ($references->fetch()) {
                respond([
                    'message' => 'Kopi tidak bisa dihapus karena sudah memiliki riwayat transaksi.',
                ], 409);
            }

            $delete = $db->prepare('DELETE FROM kopi WHERE id = ?');
            $delete->execute([$productId]);
            if ($delete->rowCount() === 0) {
                respond(['message' => 'Kopi tidak ditemukan.'], 404);
            }
            respond(['message' => 'Kopi berhasil dihapus.']);
        }

        if ($action === 'add_transaction') {
            $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId < 1 ||
                $quantity === false || $quantity < 1) {
                respond(['message' => 'Pilih kopi dan masukkan jumlah yang valid.'], 422);
            }

            $db->beginTransaction();
            $productQuery = $db->prepare(
                'SELECT id, price, stock FROM kopi WHERE id = ? FOR UPDATE'
            );
            $productQuery->execute([$productId]);
            $product = $productQuery->fetch();

            if (!$product) {
                $db->rollBack();
                respond(['message' => 'Kopi tidak ditemukan.'], 404);
            }
            if ((int) $product['stock'] < $quantity) {
                $db->rollBack();
                respond(['message' => 'Stok kopi tidak mencukupi.'], 422);
            }

            $insert = $db->prepare(
                "INSERT INTO transaksi (product_id, quantity, unit_price, total, status) " .
                "VALUES (?, ?, ?, ?, 'pending')"
            );
            $insert->execute([
                $productId,
                $quantity,
                $product['price'],
                number_format((float) $product['price'] * $quantity, 2, '.', ''),
            ]);

            $updateStock = $db->prepare('UPDATE kopi SET stock = stock - ? WHERE id = ?');
            $updateStock->execute([$quantity, $productId]);
            $db->commit();
            respond(['message' => 'Transaksi berhasil ditambahkan.'], 201);
        }

        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            respond(['message' => 'ID transaksi tidak valid.'], 422);
        }

        if ($action === 'update_transaction') {
            $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
            $status = $input['status'] ?? '';
            if ($quantity === false || $quantity < 1 ||
                !in_array($status, ['pending', 'completed', 'cancelled'], true)) {
                respond(['message' => 'Jumlah atau status transaksi tidak valid.'], 422);
            }

            $db->beginTransaction();
            $transactionQuery = $db->prepare(
                'SELECT product_id, quantity, status FROM transaksi WHERE id = ? FOR UPDATE'
            );
            $transactionQuery->execute([$id]);
            $transaction = $transactionQuery->fetch();

            if (!$transaction) {
                $db->rollBack();
                respond(['message' => 'Transaksi tidak ditemukan.'], 404);
            }

            $productQuery = $db->prepare(
                'SELECT stock FROM kopi WHERE id = ? FOR UPDATE'
            );
            $productQuery->execute([$transaction['product_id']]);
            $product = $productQuery->fetch();
            if (!$product) {
                $db->rollBack();
                respond(['message' => 'Kopi transaksi tidak ditemukan.'], 409);
            }

            $oldReserved = $transaction['status'] === 'cancelled'
                ? 0
                : (int) $transaction['quantity'];
            $newReserved = $status === 'cancelled' ? 0 : $quantity;
            $stockChange = $newReserved - $oldReserved;
            if ($stockChange > (int) $product['stock']) {
                $db->rollBack();
                respond(['message' => 'Stok kopi tidak mencukupi untuk perubahan ini.'], 422);
            }

            $updateStock = $db->prepare('UPDATE kopi SET stock = stock - ? WHERE id = ?');
            $updateStock->execute([$stockChange, $transaction['product_id']]);
            $update = $db->prepare(
                'UPDATE transaksi SET quantity = ?, total = unit_price * ?, status = ? WHERE id = ?'
            );
            $update->execute([$quantity, $quantity, $status, $id]);
            $db->commit();
            respond(['message' => 'Transaksi berhasil diperbarui.']);
        }

        $db->beginTransaction();
        $transactionQuery = $db->prepare(
            'SELECT product_id, quantity, status FROM transaksi WHERE id = ? FOR UPDATE'
        );
        $transactionQuery->execute([$id]);
        $transaction = $transactionQuery->fetch();
        if (!$transaction) {
            $db->rollBack();
            respond(['message' => 'Transaksi tidak ditemukan.'], 404);
        }

        if ($transaction['status'] !== 'cancelled') {
            $restoreStock = $db->prepare('UPDATE kopi SET stock = stock + ? WHERE id = ?');
            $restoreStock->execute([(int) $transaction['quantity'], $transaction['product_id']]);
        }
        $delete = $db->prepare('DELETE FROM transaksi WHERE id = ?');
        $delete->execute([$id]);
        $db->commit();
        respond(['message' => 'Transaksi berhasil dihapus.']);
    } catch (PDOException $error) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Dashboard write error: ' . $error->getMessage());
        respond(['message' => 'Perubahan tidak dapat disimpan. Periksa tabel database.'], 500);
    }
}

if (($action === 'auth' && $method !== 'GET') ||
    (in_array($action, ['login', 'logout', ...$writeActions], true) && $method !== 'POST') ||
    (in_array($action, $readActions, true) && $method !== 'GET')) {
    header('Allow: ' . (in_array($action, ['auth', ...$readActions], true) ? 'GET' : 'POST'));
    respond(['message' => 'Metode request tidak diizinkan.'], 405);
}

respond(['message' => 'Aksi tidak ditemukan.'], 404);
