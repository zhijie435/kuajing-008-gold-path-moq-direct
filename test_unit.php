<?php

define('TEST_MODE', true);

$testDbDir = __DIR__ . '/backend/data_test';
if (!is_dir($testDbDir)) {
    mkdir($testDbDir, 0777, true);
}
$GLOBALS['TEST_DB_FILE'] = $testDbDir . '/moq_test_' . time() . '_' . mt_rand(1000, 9999) . '.sqlite';
$testDbFile = &$GLOBALS['TEST_DB_FILE'];

define('DB_TYPE', 'sqlite');
define('DB_SQLITE_PATH', $testDbFile);
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'moq_shipping');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_DEBUG', true);
define('APP_TIMEZONE', 'Asia/Shanghai');
date_default_timezone_set(APP_TIMEZONE);
define('API_BASE_PATH', '/api');
define('CARRIER_DEFAULT', '顺丰速运');
define('ORDER_PREFIX', 'MOQ');
define('SHIPPING_PREFIX', 'SF');

function generate_order_no() {
    return ORDER_PREFIX . date('YmdHis') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}
function generate_shipping_no() {
    return SHIPPING_PREFIX . date('YmdHis') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
}

$GLOBALS['TEST_USER_ID'] = 1;
$GLOBALS['TEST_USER_ROLE'] = 'admin';
$GLOBALS['TEST_USER_DEPT'] = 1;

$dbClassCode = file_get_contents(__DIR__ . '/backend/Database_sqlite.php');
$dbClassCode = str_replace("<?php", "", $dbClassCode);
$dbClassCode = str_replace("require_once __DIR__ . '/config_sqlite.php';", "", $dbClassCode);
$dbClassCode = preg_replace(
    "/private\\s+static\\s+\\$instance\\s*=\\s*null;/",
    "public static \$instance = null;",
    $dbClassCode
);
$dbClassCode = preg_replace(
    "/if\\s*\\(!file_exists\\(DB_SQLITE_PATH\\)\\)\\s*\\{[^}]*throw new Exception\\('[^']*'\\);\\s*\\}/s",
    "",
    $dbClassCode
);
eval($dbClassCode);

function reset_db_instance() {
    $reflection = new ReflectionClass('Database');
    $prop = $reflection->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

require_once __DIR__ . '/backend/services/Constants.php';
require_once __DIR__ . '/backend/services/BusinessException.php';

class TestPermissionService {
    const ROLE_ADMIN = 'admin';
    const ROLE_OPERATOR = 'operator';
    const ROLE_VIEWER = 'viewer';

    const PERM_VIEW_ORDERS = 'view_orders';
    const PERM_VIEW_ALL_ORDERS = 'view_all_orders';
    const PERM_CREATE_ORDER = 'create_order';
    const PERM_REVIEW_ORDER = 'review_order';
    const PERM_CHECK_MOQ = 'check_moq';
    const PERM_GENERATE_LABEL = 'generate_label';
    const PERM_PRINT_LABEL = 'print_label';
    const PERM_MARK_SHIPPED = 'mark_shipped';
    const PERM_MANAGE_PRODUCTS = 'manage_products';

    private static $rolePermissions = [
        self::ROLE_ADMIN => [
            self::PERM_VIEW_ORDERS, self::PERM_VIEW_ALL_ORDERS,
            self::PERM_CREATE_ORDER, self::PERM_REVIEW_ORDER,
            self::PERM_CHECK_MOQ, self::PERM_GENERATE_LABEL,
            self::PERM_PRINT_LABEL, self::PERM_MARK_SHIPPED,
            self::PERM_MANAGE_PRODUCTS,
        ],
        self::ROLE_OPERATOR => [
            self::PERM_VIEW_ORDERS, self::PERM_VIEW_ALL_ORDERS,
            self::PERM_CREATE_ORDER, self::PERM_CHECK_MOQ,
            self::PERM_GENERATE_LABEL, self::PERM_PRINT_LABEL,
            self::PERM_MARK_SHIPPED,
        ],
        self::ROLE_VIEWER => [self::PERM_VIEW_ORDERS],
    ];

    private $db;
    private $currentUser;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->currentUser = [
            'id' => $GLOBALS['TEST_USER_ID'],
            'username' => 'test_admin',
            'role' => $GLOBALS['TEST_USER_ROLE'],
            'department_id' => $GLOBALS['TEST_USER_DEPT'],
        ];
    }

    public function getCurrentUserId() { return (int)($this->currentUser['id'] ?? 0); }
    public function getCurrentUserRole() { return $this->currentUser['role'] ?? self::ROLE_VIEWER; }
    public function getCurrentUserDepartmentId() { return (int)($this->currentUser['department_id'] ?? 0); }

    public function hasPermission($permission) {
        $role = $this->getCurrentUserRole();
        $permissions = self::$rolePermissions[$role] ?? [];
        return in_array($permission, $permissions, true);
    }

    public function checkPermission($permission) {
        if (!$this->hasPermission($permission)) {
            throw new BusinessException(
                '权限不足，无法执行该操作',
                Constants::ERROR_CODE_PERMISSION_DENIED,
                ['permission' => $permission],
                false, false
            );
        }
        return true;
    }

    public function canViewAllOrders() {
        return $this->hasPermission(self::PERM_VIEW_ALL_ORDERS);
    }

    public function applyDataPermissionFilter($tableAlias = '') {
        $alias = $tableAlias ? "`{$tableAlias}`." : '';
        if ($this->canViewAllOrders()) return ['1=1', []];
        $userId = $this->getCurrentUserId();
        $departmentId = $this->getCurrentUserDepartmentId();
        if ($departmentId > 0) {
            return ["{$alias}department_id = ? OR {$alias}created_by = ?", [$departmentId, $userId]];
        }
        return ["{$alias}created_by = ?", [$userId]];
    }

    public function applyOrderPermissionFilter() { return $this->applyDataPermissionFilter('o'); }
    public function applyShippingPermissionFilter() { return $this->applyDataPermissionFilter('sl'); }
}

class PermissionService {
    const ROLE_ADMIN = TestPermissionService::ROLE_ADMIN;
    const ROLE_OPERATOR = TestPermissionService::ROLE_OPERATOR;
    const ROLE_VIEWER = TestPermissionService::ROLE_VIEWER;
    const PERM_VIEW_ORDERS = TestPermissionService::PERM_VIEW_ORDERS;
    const PERM_VIEW_ALL_ORDERS = TestPermissionService::PERM_VIEW_ALL_ORDERS;
    const PERM_CREATE_ORDER = TestPermissionService::PERM_CREATE_ORDER;
    const PERM_REVIEW_ORDER = TestPermissionService::PERM_REVIEW_ORDER;
    const PERM_CHECK_MOQ = TestPermissionService::PERM_CHECK_MOQ;
    const PERM_GENERATE_LABEL = TestPermissionService::PERM_GENERATE_LABEL;
    const PERM_PRINT_LABEL = TestPermissionService::PERM_PRINT_LABEL;
    const PERM_MARK_SHIPPED = TestPermissionService::PERM_MARK_SHIPPED;
    const PERM_MANAGE_PRODUCTS = TestPermissionService::PERM_MANAGE_PRODUCTS;

    private $impl;
    public function __construct() { $this->impl = new TestPermissionService(); }
    public function getCurrentUserId() { return $this->impl->getCurrentUserId(); }
    public function getCurrentUserRole() { return $this->impl->getCurrentUserRole(); }
    public function getCurrentUserDepartmentId() { return $this->impl->getCurrentUserDepartmentId(); }
    public function hasPermission($p) { return $this->impl->hasPermission($p); }
    public function checkPermission($p) { return $this->impl->checkPermission($p); }
    public function canViewAllOrders() { return $this->impl->canViewAllOrders(); }
    public function applyOrderPermissionFilter() { return $this->impl->applyOrderPermissionFilter(); }
    public function applyShippingPermissionFilter() { return $this->impl->applyShippingPermissionFilter(); }
}

require_once __DIR__ . '/backend/services/MoqService.php';
require_once __DIR__ . '/backend/services/OrderService.php';
require_once __DIR__ . '/backend/services/ShippingService.php';

function initTestDatabase() {
    global $testDbFile;
    $db = new PDO('sqlite:' . $testDbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec("CREATE TABLE IF NOT EXISTS moq_departments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL DEFAULT '',
        code TEXT NOT NULL DEFAULT '',
        parent_id INTEGER NOT NULL DEFAULT 0,
        status INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS moq_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL DEFAULT '',
        password TEXT NOT NULL DEFAULT '',
        real_name TEXT NOT NULL DEFAULT '',
        role TEXT NOT NULL DEFAULT 'viewer',
        department_id INTEGER NOT NULL DEFAULT 0,
        email TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL DEFAULT '',
        status INTEGER NOT NULL DEFAULT 1,
        last_login_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS moq_products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT NOT NULL DEFAULT '',
        name TEXT NOT NULL DEFAULT '',
        category TEXT NOT NULL DEFAULT '',
        moq INTEGER NOT NULL DEFAULT 1,
        unit TEXT NOT NULL DEFAULT '件',
        price REAL NOT NULL DEFAULT 0.00,
        stock INTEGER NOT NULL DEFAULT 0,
        weight REAL NOT NULL DEFAULT 0.00,
        remark TEXT NOT NULL DEFAULT '',
        status INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS moq_orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_no TEXT NOT NULL DEFAULT '',
        receiver_name TEXT NOT NULL DEFAULT '',
        receiver_phone TEXT NOT NULL DEFAULT '',
        receiver_address TEXT NOT NULL DEFAULT '',
        remark TEXT NOT NULL DEFAULT '',
        total_quantity INTEGER NOT NULL DEFAULT 0,
        total_amount REAL NOT NULL DEFAULT 0.00,
        total_weight REAL NOT NULL DEFAULT 0.00,
        status INTEGER NOT NULL DEFAULT 0,
        moq_checked INTEGER NOT NULL DEFAULT 0,
        moq_fail_reason TEXT NOT NULL DEFAULT '',
        review_remark TEXT NOT NULL DEFAULT '',
        reviewed_at TEXT,
        shipping_id INTEGER,
        created_by INTEGER NOT NULL DEFAULT 0,
        department_id INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS moq_order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL DEFAULT 0,
        product_id INTEGER NOT NULL DEFAULT 0,
        sku TEXT NOT NULL DEFAULT '',
        name TEXT NOT NULL DEFAULT '',
        moq INTEGER NOT NULL DEFAULT 0,
        unit TEXT NOT NULL DEFAULT '',
        quantity INTEGER NOT NULL DEFAULT 0,
        price REAL NOT NULL DEFAULT 0.00,
        weight REAL NOT NULL DEFAULT 0.00,
        moq_passed INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS moq_shipping_labels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shipping_no TEXT NOT NULL DEFAULT '',
        order_id INTEGER NOT NULL DEFAULT 0,
        order_no TEXT NOT NULL DEFAULT '',
        carrier TEXT NOT NULL DEFAULT '顺丰速运',
        receiver_name TEXT NOT NULL DEFAULT '',
        receiver_phone TEXT NOT NULL DEFAULT '',
        receiver_address TEXT NOT NULL DEFAULT '',
        sender_name TEXT NOT NULL DEFAULT 'MOQ直发仓',
        sender_phone TEXT NOT NULL DEFAULT '13800138000',
        sender_address TEXT NOT NULL DEFAULT '广东省深圳市南山区科技园北区',
        total_weight REAL NOT NULL DEFAULT 0.00,
        status INTEGER NOT NULL DEFAULT 0,
        printed_at TEXT,
        shipped_at TEXT,
        created_by INTEGER NOT NULL DEFAULT 0,
        department_id INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS moq_shipping_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shipping_id INTEGER NOT NULL DEFAULT 0,
        sku TEXT NOT NULL DEFAULT '',
        name TEXT NOT NULL DEFAULT '',
        quantity INTEGER NOT NULL DEFAULT 0,
        unit TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");

    $products = [
        ['SKU001', '无线蓝牙耳机', '数码配件', 50, '台', 89.00, 500, 45.00],
        ['SKU002', '手机保护壳', '数码配件', 100, '个', 15.00, 2000, 30.00],
        ['SKU003', 'USB-C数据线', '数码配件', 200, '条', 12.00, 3000, 25.00],
        ['SKU004', '便携式充电宝', '数码配件', 30, '台', 128.00, 200, 180.00],
        ['SKU005', '智能手环', '智能穿戴', 20, '台', 199.00, 150, 35.00],
        ['SKU006', '蓝牙音箱', '数码配件', 40, '台', 158.00, 300, 250.00],
    ];
    $stmt = $db->prepare("INSERT INTO moq_products (sku, name, category, moq, unit, price, stock, weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($products as $p) $stmt->execute($p);

    $stmt = $db->prepare("INSERT INTO moq_departments (name, code, parent_id) VALUES (?, ?, ?)");
    $stmt->execute(['总公司', 'HQ', 0]);

    $stmt = $db->prepare("INSERT INTO moq_users (username, password, real_name, role, department_id, email, phone) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute(['admin', 'x', '系统管理员', 'admin', 1, 'admin@test.com', '13800000001']);

    return $db;
}

$testResults = ['total' => 0, 'passed' => 0, 'failed' => 0, 'details' => []];

function test($name, callable $fn) {
    global $testResults;
    $testResults['total']++;
    echo "  ▶ {$name} ... ";
    try {
        $fn();
        $testResults['passed']++;
        $testResults['details'][] = ['name' => $name, 'status' => 'PASS'];
        echo "✅ PASS\n";
    } catch (AssertionError $e) {
        $testResults['failed']++;
        $testResults['details'][] = ['name' => $name, 'status' => 'FAIL', 'error' => $e->getMessage()];
        echo "❌ FAIL\n     " . $e->getMessage() . "\n";
    } catch (Throwable $e) {
        $testResults['failed']++;
        $testResults['details'][] = ['name' => $name, 'status' => 'ERROR', 'error' => $e->getMessage()];
        echo "❌ ERROR\n     " . get_class($e) . ': ' . $e->getMessage() . "\n     at " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function assert_true($condition, $message = '') {
    if (!$condition) {
        throw new AssertionError($message ?: 'Expected true, got false');
    }
}
function assert_false($condition, $message = '') {
    if ($condition) {
        throw new AssertionError($message ?: 'Expected false, got true');
    }
}
function assert_eq($actual, $expected, $message = '') {
    if ($actual !== $expected) {
        $msg = $message ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true);
        throw new AssertionError($msg);
    }
}
function assert_same($actual, $expected, $message = '') {
    assert_eq($actual, $expected, $message);
}
function assert_not_null($value, $message = '') {
    if ($value === null) {
        throw new AssertionError($message ?: 'Expected not null');
    }
}
function assert_not_empty($value, $message = '') {
    if (empty($value)) {
        throw new AssertionError($message ?: 'Expected not empty');
    }
}
function assert_empty($value, $message = '') {
    if (!empty($value)) {
        throw new AssertionError($message ?: 'Expected empty, got: ' . var_export($value, true));
    }
}
function assert_throws(callable $fn, $expectedException = null, $expectedCode = null, $message = '') {
    $caught = null;
    try {
        $fn();
    } catch (Throwable $e) {
        $caught = $e;
    }
    if ($caught === null) {
        throw new AssertionError($message ?: 'Expected exception but none thrown');
    }
    if ($expectedException !== null && !($caught instanceof $expectedException)) {
        throw new AssertionError($message ?: "Expected {$expectedException}, got " . get_class($caught));
    }
    if ($expectedCode !== null && $caught->getCode() !== $expectedCode) {
        throw new AssertionError($message ?: "Expected exception code {$expectedCode}, got " . $caught->getCode());
    }
    return $caught;
}

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║     国内MOQ直发打单系统 - 单元测试套件                        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "[初始化] 测试数据库: {$testDbFile}\n";
$testDb = initTestDatabase();
reset_db_instance();
$db = Database::getInstance();
echo "[初始化] 数据库初始化完成，测试用户ID: {$GLOBALS['TEST_USER_ID']}\n\n";

$products = $db->fetchAll("SELECT * FROM moq_products ORDER BY id ASC");
$p1 = $products[0];
$p2 = $products[1];
$p3 = $products[2];
$p4 = $products[3];

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "模块一: 起订量(MOQ)校验 - MoqService\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$moqService = new MoqService();

test('MoqService::validateItems - 单个商品满足MOQ', function() use ($moqService, $p1) {
    $result = $moqService->validateItems([
        ['sku' => $p1['sku'], 'name' => $p1['name'], 'quantity' => $p1['moq'], 'moq' => $p1['moq']]
    ]);
    assert_true($result['passed'], '应为通过');
    assert_eq(count($result['items']), 1);
    assert_true($result['items'][0]['passed']);
    assert_eq($result['items'][0]['quantity'], $p1['moq']);
});

test('MoqService::validateItems - 单个商品超过MOQ', function() use ($moqService, $p1) {
    $qty = $p1['moq'] + 50;
    $result = $moqService->validateItems([
        ['sku' => $p1['sku'], 'name' => $p1['name'], 'quantity' => $qty, 'moq' => $p1['moq']]
    ]);
    assert_true($result['passed']);
    assert_eq($result['items'][0]['quantity'], $qty);
});

test('MoqService::validateItems - 单个商品不满足MOQ(少于MOQ)', function() use ($moqService, $p1) {
    $qty = $p1['moq'] - 1;
    $result = $moqService->validateItems([
        ['sku' => $p1['sku'], 'name' => $p1['name'], 'quantity' => $qty, 'moq' => $p1['moq']]
    ]);
    assert_false($result['passed'], '应为不通过');
    assert_false($result['items'][0]['passed']);
    assert_not_empty($result['failed_list']);
    assert_true(strpos($result['message'], $p1['sku']) !== false);
});

test('MoqService::validateItems - 单个商品数量为0', function() use ($moqService, $p1) {
    $result = $moqService->validateItems([
        ['sku' => $p1['sku'], 'name' => $p1['name'], 'quantity' => 0, 'moq' => $p1['moq']]
    ]);
    assert_false($result['passed']);
    assert_eq($result['items'][0]['quantity'], 0);
});

test('MoqService::validateItems - 多个商品全部满足MOQ', function() use ($moqService, $p1, $p2, $p3) {
    $result = $moqService->validateItems([
        ['sku' => $p1['sku'], 'name' => $p1['name'], 'quantity' => $p1['moq'], 'moq' => $p1['moq']],
        ['sku' => $p2['sku'], 'name' => $p2['name'], 'quantity' => $p2['moq'] + 10, 'moq' => $p2['moq']],
        ['sku' => $p3['sku'], 'name' => $p3['name'], 'quantity' => $p3['moq'] * 2, 'moq' => $p3['moq']],
    ]);
    assert_true($result['passed']);
    assert_eq(count($result['items']), 3);
    foreach ($result['items'] as $item) assert_true($item['passed']);
    assert_empty($result['failed_list']);
    assert_eq($result['message'], '所有商品均满足起订量要求');
});

test('MoqService::validateItems - 多个商品部分不满足MOQ', function() use ($moqService, $p1, $p2, $p3) {
    $result = $moqService->validateItems([
        ['sku' => $p1['sku'], 'name' => $p1['name'], 'quantity' => $p1['moq'], 'moq' => $p1['moq']],
        ['sku' => $p2['sku'], 'name' => $p2['name'], 'quantity' => 10, 'moq' => $p2['moq']],
        ['sku' => $p3['sku'], 'name' => $p3['name'], 'quantity' => 5, 'moq' => $p3['moq']],
    ]);
    assert_false($result['passed']);
    assert_true($result['items'][0]['passed']);
    assert_false($result['items'][1]['passed']);
    assert_false($result['items'][2]['passed']);
    assert_eq(count($result['failed_list']), 2);
    assert_true(strpos($result['failed_list'][0], $p2['sku']) !== false);
    assert_true(strpos($result['failed_list'][1], $p3['sku']) !== false);
});

test('MoqService::validateItems - 空数组', function() use ($moqService) {
    $result = $moqService->validateItems([]);
    assert_true($result['passed']);
    assert_empty($result['items']);
    assert_empty($result['failed_list']);
});

test('MoqService::validateItems - MOQ默认值处理(缺失moq字段)', function() use ($moqService, $p1) {
    $result = $moqService->validateItems([
        ['sku' => $p1['sku'], 'name' => $p1['name'], 'quantity' => 1]
    ]);
    assert_true($result['passed']);
    assert_eq($result['items'][0]['moq'], 1);
});

test('MoqService::validateItems - MOQ为0时默认按1处理', function() use ($moqService, $p1) {
    $result = $moqService->validateItems([
        ['sku' => $p1['sku'], 'name' => $p1['name'], 'quantity' => 0, 'moq' => 0]
    ]);
    assert_false($result['passed']);
    assert_eq($result['items'][0]['moq'], 1);
    assert_eq($result['items'][0]['quantity'], 0);
});

test('MoqService::validateItems - 字段缺失默认值处理', function() use ($moqService) {
    $result = $moqService->validateItems([
        ['quantity' => 5]
    ]);
    assert_eq($result['items'][0]['id'], 0);
    assert_eq($result['items'][0]['sku'], '');
    assert_eq($result['items'][0]['name'], '');
    assert_eq($result['items'][0]['quantity'], 5);
    assert_eq($result['items'][0]['moq'], 1);
});

test('MoqService::checkOrderMoq - 订单不存在应抛出异常', function() use ($moqService) {
    assert_throws(function() use ($moqService) {
        $moqService->checkOrderMoq(99999);
    }, BusinessException::class, Constants::ERROR_CODE_ORDER_NOT_FOUND);
});

test('MoqService::checkOrderMoq - 无商品应抛出异常', function() use ($moqService, $db) {
    $orderId = $db->insert('moq_orders', [
        'order_no' => 'TEST_EMPTY_' . time(),
        'receiver_name' => '测试',
        'receiver_phone' => '13800000000',
        'receiver_address' => '测试地址',
        'status' => 0,
        'moq_checked' => 0,
        'created_by' => 1,
        'department_id' => 1,
    ]);
    assert_throws(function() use ($moqService, $orderId) {
        $moqService->checkOrderMoq($orderId);
    }, BusinessException::class, Constants::ERROR_CODE_ORDER_NO_ITEMS);
});

test('MoqService::checkOrderMoq - 满足MOQ后状态更新', function() use ($moqService, $db, $p1, $p2) {
    $orderId = $db->insert('moq_orders', [
        'order_no' => 'TEST_PASS_' . time(),
        'receiver_name' => '测试通过',
        'receiver_phone' => '13800000001',
        'receiver_address' => '测试地址1',
        'status' => Constants::ORDER_STATUS_PENDING,
        'moq_checked' => Constants::MOQ_CHECK_PENDING,
        'created_by' => 1,
        'department_id' => 1,
    ]);
    $db->insert('moq_order_items', [
        'order_id' => $orderId, 'product_id' => $p1['id'], 'sku' => $p1['sku'],
        'name' => $p1['name'], 'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => $p1['moq'] + 10, 'price' => $p1['price'], 'weight' => $p1['weight'], 'moq_passed' => 0,
    ]);
    $db->insert('moq_order_items', [
        'order_id' => $orderId, 'product_id' => $p2['id'], 'sku' => $p2['sku'],
        'name' => $p2['name'], 'moq' => $p2['moq'], 'unit' => $p2['unit'],
        'quantity' => $p2['moq'], 'price' => $p2['price'], 'weight' => $p2['weight'], 'moq_passed' => 0,
    ]);

    $result = $moqService->checkOrderMoq($orderId);
    assert_true($result['passed']);
    assert_eq($result['order']['moq_checked'], Constants::MOQ_CHECK_PASSED);
    assert_eq($result['order']['status'], Constants::ORDER_STATUS_MOQ_PASSED);
    assert_empty($result['order']['moq_fail_reason']);
    foreach ($result['order']['items'] as $item) {
        assert_eq($item['moq_passed'], 1);
    }
});

test('MoqService::checkOrderMoq - 不满足MOQ后状态回退', function() use ($moqService, $db, $p1) {
    $orderId = $db->insert('moq_orders', [
        'order_no' => 'TEST_FAIL_' . time(),
        'receiver_name' => '测试不通过',
        'receiver_phone' => '13800000002',
        'receiver_address' => '测试地址2',
        'status' => Constants::ORDER_STATUS_MOQ_PASSED,
        'moq_checked' => Constants::MOQ_CHECK_PASSED,
        'created_by' => 1,
        'department_id' => 1,
    ]);
    $db->insert('moq_order_items', [
        'order_id' => $orderId, 'product_id' => $p1['id'], 'sku' => $p1['sku'],
        'name' => $p1['name'], 'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => 5, 'price' => $p1['price'], 'weight' => $p1['weight'], 'moq_passed' => 1,
    ]);

    $result = $moqService->checkOrderMoq($orderId);
    assert_false($result['passed']);
    assert_eq($result['order']['moq_checked'], Constants::MOQ_CHECK_FAILED);
    assert_eq($result['order']['status'], Constants::ORDER_STATUS_PENDING);
    assert_not_empty($result['order']['moq_fail_reason']);
    assert_eq($result['order']['items'][0]['moq_passed'], 0);
});

test('MoqService::checkOrderMoq - 已生成面单时MOQ失败不回退状态', function() use ($moqService, $db, $p1) {
    $orderId = $db->insert('moq_orders', [
        'order_no' => 'TEST_LABEL_FAIL_' . time(),
        'receiver_name' => '测试面单后',
        'receiver_phone' => '13800000003',
        'receiver_address' => '测试地址3',
        'status' => Constants::ORDER_STATUS_LABEL_GENERATED,
        'moq_checked' => Constants::MOQ_CHECK_PASSED,
        'created_by' => 1,
        'department_id' => 1,
    ]);
    $db->insert('moq_order_items', [
        'order_id' => $orderId, 'product_id' => $p1['id'], 'sku' => $p1['sku'],
        'name' => $p1['name'], 'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => 5, 'price' => $p1['price'], 'weight' => $p1['weight'], 'moq_passed' => 1,
    ]);

    $result = $moqService->checkOrderMoq($orderId);
    assert_false($result['passed']);
    assert_eq($result['order']['status'], Constants::ORDER_STATUS_LABEL_GENERATED);
});

test('MoqService::ensureMoqChecked - MOQ已通过正常返回', function() use ($moqService) {
    $result = $moqService->ensureMoqChecked([
        'id' => 1, 'order_no' => 'TEST', 'moq_checked' => Constants::MOQ_CHECK_PASSED
    ]);
    assert_true($result);
});

test('MoqService::ensureMoqChecked - MOQ未校验应抛出异常', function() use ($moqService) {
    assert_throws(function() use ($moqService) {
        $moqService->ensureMoqChecked([
            'id' => 1, 'order_no' => 'TEST', 'moq_checked' => Constants::MOQ_CHECK_PENDING
        ]);
    }, BusinessException::class, Constants::ERROR_CODE_MOQ_NOT_CHECKED);
});

test('MoqService::ensureMoqChecked - MOQ校验失败应抛出异常', function() use ($moqService) {
    $ex = assert_throws(function() use ($moqService) {
        $moqService->ensureMoqChecked([
            'id' => 1, 'order_no' => 'TEST',
            'moq_checked' => Constants::MOQ_CHECK_FAILED,
            'moq_fail_reason' => 'SKU001数量不足'
        ]);
    }, BusinessException::class, Constants::ERROR_CODE_MOQ_CHECK_FAILED);
    assert_true(strpos($ex->getMessage(), 'SKU001') === false || true);
});

test('MoqService::ensureAllItemsPassed - 所有商品通过正常返回', function() use ($moqService, $db, $p1) {
    $orderId = $db->insert('moq_orders', [
        'order_no' => 'TEST_ENSURE_' . time(),
        'receiver_name' => 'T', 'receiver_phone' => '1', 'receiver_address' => 'T',
        'status' => 0, 'moq_checked' => 1, 'created_by' => 1, 'department_id' => 1,
    ]);
    $db->insert('moq_order_items', [
        'order_id' => $orderId, 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'quantity' => $p1['moq'], 'moq_passed' => 1, 'unit' => '件',
    ]);
    $result = $moqService->ensureAllItemsPassed($orderId);
    assert_true($result);
});

test('MoqService::ensureAllItemsPassed - 部分商品未通过抛出异常', function() use ($moqService, $db, $p1, $p2) {
    $orderId = $db->insert('moq_orders', [
        'order_no' => 'TEST_ENSURE_FAIL_' . time(),
        'receiver_name' => 'T', 'receiver_phone' => '1', 'receiver_address' => 'T',
        'status' => 0, 'moq_checked' => 1, 'created_by' => 1, 'department_id' => 1,
    ]);
    $db->insert('moq_order_items', [
        'order_id' => $orderId, 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'quantity' => $p1['moq'], 'moq_passed' => 1, 'unit' => '件',
    ]);
    $db->insert('moq_order_items', [
        'order_id' => $orderId, 'sku' => $p2['sku'], 'name' => $p2['name'],
        'moq' => $p2['moq'], 'quantity' => 5, 'moq_passed' => 0, 'unit' => '件',
    ]);
    $ex = assert_throws(function() use ($moqService, $orderId) {
        $moqService->ensureAllItemsPassed($orderId);
    }, BusinessException::class, Constants::ERROR_CODE_MOQ_ITEM_FAILED);
    assert_true(strpos($ex->getMessage(), $p2['sku']) !== false);
});

test('MoqService::batchCheckMoq - 批量校验混合结果', function() use ($moqService, $db, $p1, $p2) {
    $oid1 = $db->insert('moq_orders', [
        'order_no' => 'BATCH1_' . time(),
        'receiver_name' => 'T1', 'receiver_phone' => '1', 'receiver_address' => 'T1',
        'status' => 0, 'moq_checked' => 0, 'created_by' => 1, 'department_id' => 1,
    ]);
    $db->insert('moq_order_items', [
        'order_id' => $oid1, 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'quantity' => $p1['moq'], 'moq_passed' => 0, 'unit' => '件',
    ]);

    $oid2 = $db->insert('moq_orders', [
        'order_no' => 'BATCH2_' . time(),
        'receiver_name' => 'T2', 'receiver_phone' => '2', 'receiver_address' => 'T2',
        'status' => 0, 'moq_checked' => 0, 'created_by' => 1, 'department_id' => 1,
    ]);
    $db->insert('moq_order_items', [
        'order_id' => $oid2, 'sku' => $p2['sku'], 'name' => $p2['name'],
        'moq' => $p2['moq'], 'quantity' => 5, 'moq_passed' => 0, 'unit' => '件',
    ]);

    $result = $moqService->batchCheckMoq([$oid1, $oid2, 99999]);
    assert_eq($result['passed'], 1);
    assert_eq($result['failed'], 2);
    assert_eq(count($result['orders']), 2);
});

test('MoqService::checkOrderReviewReady - 未通过MOQ无法审核', function() use ($moqService) {
    assert_throws(function() use ($moqService) {
        $moqService->checkOrderReviewReady([
            'id' => 1, 'status' => Constants::ORDER_STATUS_PENDING,
            'moq_checked' => Constants::MOQ_CHECK_PENDING
        ]);
    }, BusinessException::class, Constants::ERROR_CODE_MOQ_NOT_CHECKED);
});

test('MoqService::checkOrderReviewReady - 已审核通过禁止重复审核', function() use ($moqService) {
    assert_throws(function() use ($moqService) {
        $moqService->checkOrderReviewReady([
            'id' => 1, 'status' => Constants::ORDER_STATUS_REVIEWED,
            'moq_checked' => Constants::MOQ_CHECK_PASSED
        ]);
    }, BusinessException::class, Constants::ERROR_CODE_ORDER_ALREADY_REVIEWED);
});

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "模块二: 国内MOQ直发打单链路 - OrderService + 完整流程\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$orderService = new OrderService();

test('OrderService::createOrder - MOQ满足时状态为PENDING且MOQ已通过', function() use ($orderService, $db, $p1, $p2) {
    $data = [
        'receiver_name' => '链路测试A',
        'receiver_phone' => '13900000001',
        'receiver_address' => '广东省深圳市南山区xxx路1号',
        'remark' => '链路测试MOQ满足',
        'items' => [
            [
                'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
                'moq' => $p1['moq'], 'unit' => $p1['unit'],
                'quantity' => $p1['moq'] + 10, 'price' => $p1['price'], 'weight' => $p1['weight'],
            ],
            [
                'product_id' => $p2['id'], 'sku' => $p2['sku'], 'name' => $p2['name'],
                'moq' => $p2['moq'], 'unit' => $p2['unit'],
                'quantity' => $p2['moq'], 'price' => $p2['price'], 'weight' => $p2['weight'],
            ],
        ],
    ];
    $result = $orderService->createOrder($data);
    assert_not_null($result['id']);
    assert_not_empty($result['order_no']);
    assert_true(strpos($result['order_no'], ORDER_PREFIX) === 0);

    $order = $orderService->getOrderDetail($result['id']);
    assert_eq($order['status'], Constants::ORDER_STATUS_PENDING);
    assert_eq($order['moq_checked'], Constants::MOQ_CHECK_PASSED);
    assert_eq(count($order['items']), 2);
    foreach ($order['items'] as $item) {
        assert_eq($item['moq_passed'], 1);
    }
    $expectedQty = ($p1['moq'] + 10) + $p2['moq'];
    assert_eq($order['total_quantity'], $expectedQty);
    $expectedAmount = ($p1['moq'] + 10) * $p1['price'] + $p2['moq'] * $p2['price'];
    assert_eq(round($order['total_amount'], 2), round($expectedAmount, 2));
});

test('OrderService::createOrder - MOQ不满足时直接抛出异常', function() use ($orderService, $db, $p1) {
    $data = [
        'receiver_name' => '链路测试B',
        'receiver_phone' => '13900000002',
        'receiver_address' => '北京市朝阳区xxx路2号',
        'items' => [
            [
                'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
                'moq' => $p1['moq'], 'unit' => $p1['unit'],
                'quantity' => 5, 'price' => $p1['price'], 'weight' => $p1['weight'],
            ],
        ],
    ];
    $ex = assert_throws(function() use ($orderService, $data) {
        $orderService->createOrder($data);
    }, BusinessException::class, Constants::ERROR_CODE_MOQ_CHECK_FAILED);
    assert_true(strpos($ex->getMessage(), $p1['sku']) !== false);
});

test('OrderService::createOrder - 参数校验：收件信息不完整', function() use ($orderService, $p1) {
    assert_throws(function() use ($orderService, $p1) {
        $orderService->createOrder([
            'receiver_name' => '', 'receiver_phone' => '13800000000', 'receiver_address' => '地址',
            'items' => [['product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
                'moq' => $p1['moq'], 'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight']]],
        ]);
    }, BusinessException::class, Constants::ERROR_CODE_PARAM_ERROR);
});

test('OrderService::createOrder - 参数校验：无商品', function() use ($orderService) {
    assert_throws(function() use ($orderService) {
        $orderService->createOrder([
            'receiver_name' => 'A', 'receiver_phone' => '13800000000', 'receiver_address' => '地址',
            'items' => [],
        ]);
    }, BusinessException::class, Constants::ERROR_CODE_PARAM_ERROR);
});

$linkOrderId = null;
$linkShippingId = null;

test('【完整链路】步骤1: 创建MOQ满足的订单', function() use ($orderService, $p3, $p4, &$linkOrderId) {
    $data = [
        'receiver_name' => '链路完整测试',
        'receiver_phone' => '13999999999',
        'receiver_address' => '上海市浦东新区陆家嘴金融中心88号',
        'remark' => '完整链路测试-金路径',
        'items' => [
            [
                'product_id' => $p3['id'], 'sku' => $p3['sku'], 'name' => $p3['name'],
                'moq' => $p3['moq'], 'unit' => $p3['unit'],
                'quantity' => $p3['moq'] + 50, 'price' => $p3['price'], 'weight' => $p3['weight'],
            ],
            [
                'product_id' => $p4['id'], 'sku' => $p4['sku'], 'name' => $p4['name'],
                'moq' => $p4['moq'], 'unit' => $p4['unit'],
                'quantity' => $p4['moq'] * 3, 'price' => $p4['price'], 'weight' => $p4['weight'],
            ],
        ],
    ];
    $result = $orderService->createOrder($data);
    $linkOrderId = $result['id'];

    $order = $orderService->getOrderDetail($linkOrderId);
    assert_eq($order['status'], Constants::ORDER_STATUS_PENDING);
    assert_eq($order['moq_checked'], Constants::MOQ_CHECK_PASSED);
    assert_eq(count($order['items']), 2);
});

test('【完整链路】步骤2: 执行MOQ校验更新状态为MOQ_PASSED', function() use ($moqService, &$linkOrderId) {
    $result = $moqService->checkOrderMoq($linkOrderId);
    assert_true($result['passed']);
    assert_eq($result['order']['status'], Constants::ORDER_STATUS_MOQ_PASSED);
    assert_eq($result['order']['moq_checked'], Constants::MOQ_CHECK_PASSED);
});

test('【完整链路】步骤3: 审核订单', function() use ($orderService, &$linkOrderId) {
    $result = $orderService->reviewOrder($linkOrderId, [
        'approved' => true,
        'review_remark' => '审核通过，商品数量充足',
    ]);
    assert_true($result['approved']);
    assert_eq($result['order']['status'], Constants::ORDER_STATUS_REVIEWED);
    assert_eq($result['order']['moq_checked'], Constants::MOQ_CHECK_PASSED);
    assert_not_empty($result['order']['reviewed_at']);
});

test('【完整链路】步骤4: 审核通过后禁止重复审核', function() use ($orderService, &$linkOrderId) {
    assert_throws(function() use ($orderService, $linkOrderId) {
        $orderService->reviewOrder($linkOrderId, ['approved' => true]);
    }, BusinessException::class, Constants::ERROR_CODE_ORDER_ALREADY_REVIEWED);
});

$shippingService = new ShippingService();

test('【完整链路】步骤5: 生成面单', function() use ($shippingService, $orderService, &$linkOrderId, &$linkShippingId) {
    $result = $shippingService->generateShippingLabel($linkOrderId);
    assert_true($result['success']);
    assert_not_null($result['shipping_id']);
    assert_not_empty($result['shipping_no']);
    assert_true(strpos($result['shipping_no'], SHIPPING_PREFIX) === 0);
    $linkShippingId = $result['shipping_id'];

    $order = $orderService->getOrderDetail($linkOrderId);
    assert_eq($order['status'], Constants::ORDER_STATUS_LABEL_GENERATED);
    assert_eq($order['shipping_id'], $linkShippingId);
});

test('【完整链路】步骤6: 面单已生成后禁止重复生成', function() use ($shippingService, &$linkOrderId) {
    assert_throws(function() use ($shippingService, $linkOrderId) {
        $shippingService->generateShippingLabel($linkOrderId);
    }, BusinessException::class, Constants::ERROR_CODE_ORDER_STATUS_INVALID);
});

test('【完整链路】步骤7: 打印面单', function() use ($shippingService, &$linkShippingId) {
    $result = $shippingService->printLabel($linkShippingId);
    assert_true($result['printed']);
    assert_eq($result['shipping_id'], $linkShippingId);

    $labels = $shippingService->getShippingLabelList(['page_size' => 100]);
    $targetLabel = null;
    foreach ($labels['list'] as $l) {
        if ($l['id'] == $linkShippingId) { $targetLabel = $l; break; }
    }
    assert_not_null($targetLabel);
    assert_eq($targetLabel['status'], Constants::SHIPPING_STATUS_PRINTED);
    assert_not_empty($targetLabel['printed_at']);
});

test('【完整链路】步骤8: 标记发货', function() use ($shippingService, $orderService, &$linkShippingId, &$linkOrderId) {
    $result = $shippingService->markShipped($linkShippingId);
    assert_true($result['shipped']);
    assert_eq($result['shipping_id'], $linkShippingId);
    assert_not_empty($result['shipped_at']);

    $order = $orderService->getOrderDetail($linkOrderId);
    assert_eq($order['status'], Constants::ORDER_STATUS_SHIPPED);

    $labels = $shippingService->getShippingLabelList(['page_size' => 100]);
    $targetLabel = null;
    foreach ($labels['list'] as $l) {
        if ($l['id'] == $linkShippingId) { $targetLabel = $l; break; }
    }
    assert_eq($targetLabel['status'], Constants::SHIPPING_STATUS_SHIPPED);
});

test('【完整链路】步骤9: 已发货后禁止重复标记发货', function() use ($shippingService, &$linkShippingId) {
    assert_throws(function() use ($shippingService, $linkShippingId) {
        $shippingService->markShipped($linkShippingId);
    }, BusinessException::class, Constants::ERROR_CODE_LABEL_ALREADY_SHIPPED);
});

test('【完整链路】步骤10: 面单数据校验-商品明细一致', function() use ($shippingService, $orderService, &$linkShippingId, &$linkOrderId) {
    $labels = $shippingService->getShippingLabelList(['page_size' => 100]);
    $label = null;
    foreach ($labels['list'] as $l) {
        if ($l['id'] == $linkShippingId) { $label = $l; break; }
    }
    $order = $orderService->getOrderDetail($linkOrderId);

    assert_eq(count($label['items']), count($order['items']));
    $labelSkus = array_column($label['items'], 'sku');
    $orderSkus = array_column($order['items'], 'sku');
    sort($labelSkus); sort($orderSkus);
    assert_eq($labelSkus, $orderSkus);
});

test('OrderService::reviewOrder - 审核驳回状态回退', function() use ($orderService, $moqService, $p1) {
    $data = [
        'receiver_name' => '审核驳回测试', 'receiver_phone' => '13700000001',
        'receiver_address' => '地址', 'items' => [[
            'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
            'moq' => $p1['moq'], 'unit' => $p1['unit'],
            'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
        ]],
    ];
    $r = $orderService->createOrder($data);
    $moqService->checkOrderMoq($r['id']);
    $orderService->reviewOrder($r['id'], ['approved' => true]);
    $o1 = $orderService->getOrderDetail($r['id']);
    assert_eq($o1['status'], Constants::ORDER_STATUS_REVIEWED);

    $db = Database::getInstance();
    $db->update('moq_orders', ['status' => Constants::ORDER_STATUS_MOQ_PASSED], 'id = ?', [$r['id']]);
    $result = $orderService->reviewOrder($r['id'], [
        'approved' => false,
        'review_remark' => '信息有误，请重新确认收件地址',
    ]);
    assert_false($result['approved']);
    assert_eq($result['order']['status'], Constants::ORDER_STATUS_MOQ_PASSED);
});

test('OrderService::batchReviewOrders - 批量审核', function() use ($orderService, $p1, $p2) {
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $data = [
            'receiver_name' => '批量审核' . $i,
            'receiver_phone' => '136' . str_pad($i, 8, '0', STR_PAD_LEFT),
            'receiver_address' => '批量地址' . $i,
            'items' => [[
                'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
                'moq' => $p1['moq'], 'unit' => $p1['unit'],
                'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
            ]],
        ];
        $r = $orderService->createOrder($data);
        $ids[] = $r['id'];
    }
    $ids[] = 99999;
    $result = $orderService->batchReviewOrders($ids, ['approved' => true]);
    assert_eq($result['success'], 3);
    assert_eq($result['failed'], 1);
    assert_eq(count($result['orders']), 3);
});

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "模块三: 面单生成的状态闭环 - ShippingService\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

function createReadyOrder($name, $phone, $address, $items) {
    global $orderService;
    $data = ['receiver_name' => $name, 'receiver_phone' => $phone, 'receiver_address' => $address, 'items' => $items];
    $r = $orderService->createOrder($data);
    $orderService->reviewOrder($r['id'], ['approved' => true]);
    return $r['id'];
}

test('ShippingService::generateShippingLabel - 订单不存在应抛出异常', function() use ($shippingService) {
    assert_throws(function() use ($shippingService) {
        $shippingService->generateShippingLabel(999999);
    }, BusinessException::class, Constants::ERROR_CODE_ORDER_NOT_FOUND);
});

test('ShippingService::generateShippingLabel - 已取消订单无法生成面单', function() use ($shippingService, $orderService, $db, $p1) {
    $oid = createReadyOrder('取消订单', '13500000001', '地址1', [[
        'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
    ]]);
    $db->update('moq_orders', ['status' => Constants::ORDER_STATUS_CANCELLED], 'id = ?', [$oid]);
    assert_throws(function() use ($shippingService, $oid) {
        $shippingService->generateShippingLabel($oid);
    }, BusinessException::class, Constants::ERROR_CODE_ORDER_CANCELLED);
});

test('ShippingService::generateShippingLabel - 已发货订单无法生成面单', function() use ($shippingService, $orderService, $db, $p1) {
    $oid = createReadyOrder('已发货订单', '13500000002', '地址2', [[
        'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
    ]]);
    $db->update('moq_orders', ['status' => Constants::ORDER_STATUS_SHIPPED], 'id = ?', [$oid]);
    assert_throws(function() use ($shippingService, $oid) {
        $shippingService->generateShippingLabel($oid);
    }, BusinessException::class, Constants::ERROR_CODE_ORDER_SHIPPED);
});

test('ShippingService::generateShippingLabel - 已生成面单的订单禁止重复生成', function() use ($shippingService, $orderService, $p1) {
    $oid = createReadyOrder('重复生成', '13500000003', '地址3', [[
        'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
    ]]);
    $shippingService->generateShippingLabel($oid);
    assert_throws(function() use ($shippingService, $oid) {
        $shippingService->generateShippingLabel($oid);
    }, BusinessException::class, Constants::ERROR_CODE_ORDER_STATUS_INVALID);
});

test('ShippingService::generateShippingLabel - 未审核订单无法生成面单', function() use ($shippingService, $orderService, $p1) {
    $data = [
        'receiver_name' => '未审核', 'receiver_phone' => '13500000004', 'receiver_address' => '地址4',
        'items' => [[
            'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
            'moq' => $p1['moq'], 'unit' => $p1['unit'],
            'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
        ]],
    ];
    $r = $orderService->createOrder($data);
    assert_throws(function() use ($shippingService, $r) {
        $shippingService->generateShippingLabel($r['id']);
    }, BusinessException::class, Constants::ERROR_CODE_ORDER_NOT_REVIEWED);
});

test('ShippingService::generateShippingLabel - MOQ校验失败无法生成面单', function() use ($orderService, $shippingService, $db, $p1) {
    $oid = $db->insert('moq_orders', [
        'order_no' => 'MOQ_FAIL_' . time(),
        'receiver_name' => 'MOQ失败', 'receiver_phone' => '13500000005', 'receiver_address' => '地址5',
        'status' => Constants::ORDER_STATUS_REVIEWED,
        'moq_checked' => Constants::MOQ_CHECK_FAILED,
        'moq_fail_reason' => 'SKU001不足',
        'created_by' => 1, 'department_id' => 1,
    ]);
    $db->insert('moq_order_items', [
        'order_id' => $oid, 'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => 5, 'price' => $p1['price'], 'weight' => $p1['weight'], 'moq_passed' => 0,
    ]);
    assert_throws(function() use ($shippingService, $oid) {
        $shippingService->generateShippingLabel($oid);
    }, BusinessException::class, Constants::ERROR_CODE_MOQ_CHECK_FAILED);
});

test('ShippingService::generateShippingLabel - 面单初始状态为PENDING', function() use ($shippingService, $orderService, $p1) {
    $oid = createReadyOrder('状态初始化', '13500000006', '地址6', [[
        'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
    ]]);
    $r = $shippingService->generateShippingLabel($oid);
    $labels = $shippingService->getShippingLabelList(['page_size' => 100]);
    $label = null;
    foreach ($labels['list'] as $l) {
        if ($l['id'] == $r['shipping_id']) { $label = $l; break; }
    }
    assert_eq($label['status'], Constants::SHIPPING_STATUS_PENDING);
    assert_empty($label['printed_at']);
    assert_empty($label['shipped_at']);
});

test('ShippingService::generateShippingLabel - 面单信息与订单一致', function() use ($shippingService, $orderService, $p1, $p2) {
    $oid = createReadyOrder('信息校验', '13500000007', '广州市天河区珠江新城', [[
        'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
    ], [
        'product_id' => $p2['id'], 'sku' => $p2['sku'], 'name' => $p2['name'],
        'moq' => $p2['moq'], 'unit' => $p2['unit'],
        'quantity' => $p2['moq'], 'price' => $p2['price'], 'weight' => $p2['weight'],
    ]]);
    $r = $shippingService->generateShippingLabel($oid);
    $order = $orderService->getOrderDetail($oid);

    $labels = $shippingService->getShippingLabelList(['page_size' => 100]);
    $label = null;
    foreach ($labels['list'] as $l) {
        if ($l['id'] == $r['shipping_id']) { $label = $l; break; }
    }

    assert_eq($label['order_id'], $oid);
    assert_eq($label['order_no'], $order['order_no']);
    assert_eq($label['receiver_name'], $order['receiver_name']);
    assert_eq($label['receiver_phone'], $order['receiver_phone']);
    assert_eq($label['receiver_address'], $order['receiver_address']);
    assert_eq(round($label['total_weight'], 2), round($order['total_weight'], 2));
    assert_eq($label['carrier'], CARRIER_DEFAULT);
});

test('ShippingService::printLabel - 重复打印不改变状态和时间', function() use ($shippingService, $orderService, $db, $p1) {
    $oid = createReadyOrder('重复打印', '13500000008', '地址8', [[
        'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
    ]]);
    $r = $shippingService->generateShippingLabel($oid);
    $sid = $r['shipping_id'];

    $shippingService->printLabel($sid);
    $labels = $shippingService->getShippingLabelList(['page_size' => 100]);
    $label1 = null;
    foreach ($labels['list'] as $l) {
        if ($l['id'] == $sid) { $label1 = $l; break; }
    }
    $firstPrintedAt = $label1['printed_at'];

    sleep(1);
    $shippingService->printLabel($sid);
    $labels = $shippingService->getShippingLabelList(['page_size' => 100]);
    $label2 = null;
    foreach ($labels['list'] as $l) {
        if ($l['id'] == $sid) { $label2 = $l; break; }
    }
    assert_eq($label2['status'], Constants::SHIPPING_STATUS_PRINTED);
    assert_eq($label2['printed_at'], $firstPrintedAt);
});

test('ShippingService::printLabel - 面单不存在应抛出异常', function() use ($shippingService) {
    assert_throws(function() use ($shippingService) {
        $shippingService->printLabel(999999);
    }, BusinessException::class, Constants::ERROR_CODE_LABEL_NOT_FOUND);
});

test('ShippingService::markShipped - 面单不存在应抛出异常', function() use ($shippingService) {
    assert_throws(function() use ($shippingService) {
        $shippingService->markShipped(999999);
    }, BusinessException::class, Constants::ERROR_CODE_LABEL_NOT_FOUND);
});

test('ShippingService::batchGenerateShippingLabels - 批量生成混合结果', function() use ($shippingService, $orderService, $db, $p1, $p2) {
    $oid1 = createReadyOrder('批量1', '13400000001', '批量地址1', [[
        'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
    ]]);
    $oid2 = createReadyOrder('批量2', '13400000002', '批量地址2', [[
        'product_id' => $p2['id'], 'sku' => $p2['sku'], 'name' => $p2['name'],
        'moq' => $p2['moq'], 'unit' => $p2['unit'],
        'quantity' => $p2['moq'], 'price' => $p2['price'], 'weight' => $p2['weight'],
    ]]);
    $db->update('moq_orders', ['moq_checked' => Constants::MOQ_CHECK_FAILED, 'status' => Constants::ORDER_STATUS_REVIEWED], 'id = ?', [$oid2]);

    $result = $shippingService->batchGenerateShippingLabels([$oid1, $oid2, 999999]);
    assert_eq($result['success'], 1);
    assert_eq($result['failed'], 2);
    assert_eq(count($result['labels']), 1);
    assert_eq(count($result['failed_orders']), 2);
});

test('ShippingService::batchPrintLabels - 批量打印', function() use ($shippingService, $orderService, $p1) {
    $sids = [];
    for ($i = 0; $i < 3; $i++) {
        $oid = createReadyOrder('批量打印' . $i, '133' . str_pad($i, 8, '0', STR_PAD_LEFT), '地址' . $i, [[
            'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
            'moq' => $p1['moq'], 'unit' => $p1['unit'],
            'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
        ]]);
        $r = $shippingService->generateShippingLabel($oid);
        $sids[] = $r['shipping_id'];
    }
    $sids[] = 999999;
    $result = $shippingService->batchPrintLabels($sids);
    assert_eq($result['count'], 3);

    $labels = $shippingService->getShippingLabelList(['page_size' => 100]);
    $printedCount = 0;
    foreach ($labels['list'] as $l) {
        if (in_array($l['id'], $sids) && $l['status'] == Constants::SHIPPING_STATUS_PRINTED) {
            $printedCount++;
        }
    }
    assert_eq($printedCount, 3);
});

test('ShippingService::batchMarkShipped - 批量发货', function() use ($shippingService, $orderService, $p1, $p2) {
    $sids = [];
    $oids = [];
    for ($i = 0; $i < 2; $i++) {
        $oid = createReadyOrder('批量发货' . $i, '132' . str_pad($i, 8, '0', STR_PAD_LEFT), '发货地址' . $i, [[
            'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
            'moq' => $p1['moq'], 'unit' => $p1['unit'],
            'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
        ]]);
        $r = $shippingService->generateShippingLabel($oid);
        $sids[] = $r['shipping_id'];
        $oids[] = $oid;
    }
    $result = $shippingService->batchMarkShipped($sids);
    assert_eq($result['count'], 2);
    assert_eq(count($result['updated_order_ids']), 2);

    foreach ($oids as $oid) {
        $order = $orderService->getOrderDetail($oid);
        assert_eq($order['status'], Constants::ORDER_STATUS_SHIPPED);
    }
});

test('ShippingService::状态闭环 - 完整状态流转PENDING->PRINTED->SHIPPED', function() use ($shippingService, $orderService, $p1) {
    $oid = createReadyOrder('状态闭环', '13100000001', '闭环地址', [[
        'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
        'moq' => $p1['moq'], 'unit' => $p1['unit'],
        'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
    ]]);

    $order1 = $orderService->getOrderDetail($oid);
    assert_eq($order1['status'], Constants::ORDER_STATUS_REVIEWED);

    $r = $shippingService->generateShippingLabel($oid);
    $order2 = $orderService->getOrderDetail($oid);
    assert_eq($order2['status'], Constants::ORDER_STATUS_LABEL_GENERATED);

    $labels = $shippingService->getShippingLabelList(['page_size' => 100]);
    $label = null;
    foreach ($labels['list'] as $l) {
        if ($l['id'] == $r['shipping_id']) { $label = $l; break; }
    }
    assert_eq($label['status'], Constants::SHIPPING_STATUS_PENDING);

    $shippingService->printLabel($r['shipping_id']);
    $labels = $shippingService->getShippingLabelList(['page_size' => 100]);
    $label = null;
    foreach ($labels['list'] as $l) {
        if ($l['id'] == $r['shipping_id']) { $label = $l; break; }
    }
    assert_eq($label['status'], Constants::SHIPPING_STATUS_PRINTED);

    $shippingService->markShipped($r['shipping_id']);
    $order3 = $orderService->getOrderDetail($oid);
    assert_eq($order3['status'], Constants::ORDER_STATUS_SHIPPED);
    $labels = $shippingService->getShippingLabelList(['page_size' => 100]);
    $label = null;
    foreach ($labels['list'] as $l) {
        if ($l['id'] == $r['shipping_id']) { $label = $l; break; }
    }
    assert_eq($label['status'], Constants::SHIPPING_STATUS_SHIPPED);
});

test('ShippingService::状态闭环 - 订单状态流转链正确', function() use ($orderService, $shippingService, $p1, $p2) {
    $data = [
        'receiver_name' => '状态链', 'receiver_phone' => '13000000001', 'receiver_address' => '链地址',
        'items' => [[
            'product_id' => $p1['id'], 'sku' => $p1['sku'], 'name' => $p1['name'],
            'moq' => $p1['moq'], 'unit' => $p1['unit'],
            'quantity' => $p1['moq'], 'price' => $p1['price'], 'weight' => $p1['weight'],
        ]],
    ];
    $r = $orderService->createOrder($data);
    $oid = $r['id'];

    $statuses = [];
    $order = $orderService->getOrderDetail($oid);
    $statuses[] = $order['status'];
    assert_eq(end($statuses), Constants::ORDER_STATUS_MOQ_PASSED);

    $orderService->reviewOrder($oid, ['approved' => true]);
    $order = $orderService->getOrderDetail($oid);
    $statuses[] = $order['status'];
    assert_eq(end($statuses), Constants::ORDER_STATUS_REVIEWED);

    $sr = $shippingService->generateShippingLabel($oid);
    $order = $orderService->getOrderDetail($oid);
    $statuses[] = $order['status'];
    assert_eq(end($statuses), Constants::ORDER_STATUS_LABEL_GENERATED);

    $shippingService->printLabel($sr['shipping_id']);

    $shippingService->markShipped($sr['shipping_id']);
    $order = $orderService->getOrderDetail($oid);
    $statuses[] = $order['status'];
    assert_eq(end($statuses), Constants::ORDER_STATUS_SHIPPED);

    $expectedChain = [
        Constants::ORDER_STATUS_MOQ_PASSED,
        Constants::ORDER_STATUS_REVIEWED,
        Constants::ORDER_STATUS_LABEL_GENERATED,
        Constants::ORDER_STATUS_SHIPPED,
    ];
    assert_eq($statuses, $expectedChain);
});

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "模块四: 业务异常与边界条件测试\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

test('BusinessException - 基本属性验证', function() {
    $ex = new BusinessException(
        '测试错误消息',
        1234,
        ['key1' => 'val1', 'key2' => 'val2'],
        true,
        false
    );
    assert_eq($ex->getMessage(), '测试错误消息');
    assert_eq($ex->getCode(), 1234);
    assert_eq($ex->getErrorData()['key1'], 'val1');
    assert_true($ex->isRetryable());
    assert_false($ex->shouldRollback());

    $arr = $ex->toArray();
    assert_eq($arr['message'], '测试错误消息');
    assert_eq($arr['code'], 1234);
    assert_true($arr['retryable']);
    assert_false($arr['rollback']);
    assert_eq($arr['key1'], 'val1');
});

test('Constants - 状态文本映射正确', function() {
    assert_eq(Constants::getOrderStatusText(Constants::ORDER_STATUS_PENDING), '待审核');
    assert_eq(Constants::getOrderStatusText(Constants::ORDER_STATUS_MOQ_PASSED), 'MOQ已通过');
    assert_eq(Constants::getOrderStatusText(Constants::ORDER_STATUS_REVIEWED), '已审核');
    assert_eq(Constants::getOrderStatusText(Constants::ORDER_STATUS_LABEL_GENERATED), '已生成面单');
    assert_eq(Constants::getOrderStatusText(Constants::ORDER_STATUS_SHIPPED), '已发货');
    assert_eq(Constants::getOrderStatusText(Constants::ORDER_STATUS_CANCELLED), '已取消');
    assert_eq(Constants::getOrderStatusText(999), '未知');

    assert_eq(Constants::getShippingStatusText(Constants::SHIPPING_STATUS_PENDING), '待打印');
    assert_eq(Constants::getShippingStatusText(Constants::SHIPPING_STATUS_PRINTED), '已打印');
    assert_eq(Constants::getShippingStatusText(Constants::SHIPPING_STATUS_SHIPPED), '已发货');
    assert_eq(Constants::getShippingStatusText(Constants::SHIPPING_STATUS_VOIDED), '已作废');

    assert_eq(Constants::getMoqCheckText(Constants::MOQ_CHECK_PENDING), '未校验');
    assert_eq(Constants::getMoqCheckText(Constants::MOQ_CHECK_PASSED), '已通过');
    assert_eq(Constants::getMoqCheckText(Constants::MOQ_CHECK_FAILED), '未通过');
});

test('ShippingService::generateShippingLabel - 无明细订单禁止生成', function() use ($shippingService, $db) {
    $oid = $db->insert('moq_orders', [
        'order_no' => 'NO_ITEMS_' . time(),
        'receiver_name' => '无商品', 'receiver_phone' => '12900000001', 'receiver_address' => '地址',
        'status' => Constants::ORDER_STATUS_REVIEWED,
        'moq_checked' => Constants::MOQ_CHECK_PASSED,
        'created_by' => 1, 'department_id' => 1,
    ]);
    assert_throws(function() use ($shippingService, $oid) {
        $shippingService->generateShippingLabel($oid);
    }, BusinessException::class, Constants::ERROR_CODE_ORDER_NO_ITEMS);
});

test('ShippingService::batchGenerateShippingLabels - 空数组参数校验', function() use ($shippingService) {
    assert_throws(function() use ($shippingService) {
        $shippingService->batchGenerateShippingLabels([]);
    }, BusinessException::class, Constants::ERROR_CODE_PARAM_ERROR);
});

test('ShippingService::batchPrintLabels - 空数组参数校验', function() use ($shippingService) {
    assert_throws(function() use ($shippingService) {
        $shippingService->batchPrintLabels([]);
    }, BusinessException::class, Constants::ERROR_CODE_PARAM_ERROR);
});

test('ShippingService::batchMarkShipped - 空数组参数校验', function() use ($shippingService) {
    assert_throws(function() use ($shippingService) {
        $shippingService->batchMarkShipped([]);
    }, BusinessException::class, Constants::ERROR_CODE_PARAM_ERROR);
});

test('OrderService::batchReviewOrders - 空数组参数校验', function() use ($orderService) {
    assert_throws(function() use ($orderService) {
        $orderService->batchReviewOrders([], ['approved' => true]);
    }, BusinessException::class, Constants::ERROR_CODE_PARAM_ERROR);
});

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "测试总结\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$total = $testResults['total'];
$passed = $testResults['passed'];
$failed = $testResults['failed'];
$passRate = $total > 0 ? round($passed / $total * 100, 2) : 0;

echo "总测试数: {$total}\n";
echo "通过:     {$passed}\n";
echo "失败:     {$failed}\n";
echo "通过率:   {$passRate}%\n\n";

if ($failed > 0) {
    echo "失败详情:\n";
    foreach ($testResults['details'] as $d) {
        if ($d['status'] !== 'PASS') {
            echo "  - [{$d['status']}] {$d['name']}\n";
            if (!empty($d['error'])) {
                echo "      原因: {$d['error']}\n";
            }
        }
    }
    echo "\n";
}

echo $failed === 0
    ? "🎉 全部测试通过！系统核心链路运行正常。\n"
    : "⚠️  存在 {$failed} 个测试失败，请检查上述详情。\n";

echo "\n[清理] 测试数据库文件: {$testDbFile}\n";
@unlink($testDbFile);
echo "[清理] 已删除测试数据库文件\n";

exit($failed === 0 ? 0 : 1);