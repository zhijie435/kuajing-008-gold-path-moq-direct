<?php
$dbFile = __DIR__ . '/data/moq_shipping.sqlite';
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0777, true);
}

$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

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

$count = $db->query("SELECT COUNT(*) FROM moq_products")->fetchColumn();
if ($count == 0) {
    $products = [
        ['SKU001', '无线蓝牙耳机', '数码配件', 50, '台', 89.00, 500, 45.00, '标准款蓝牙耳机'],
        ['SKU002', '手机保护壳', '数码配件', 100, '个', 15.00, 2000, 30.00, '通用透明手机壳'],
        ['SKU003', 'USB-C数据线', '数码配件', 200, '条', 12.00, 3000, 25.00, '1米快充数据线'],
        ['SKU004', '便携式充电宝', '数码配件', 30, '台', 128.00, 200, 180.00, '10000mAh移动电源'],
        ['SKU005', '智能手环', '智能穿戴', 20, '台', 199.00, 150, 35.00, '健康监测手环'],
        ['SKU006', '蓝牙音箱', '数码配件', 40, '台', 158.00, 300, 250.00, '迷你便携音箱'],
        ['SKU007', '无线充电器', '数码配件', 80, '台', 68.00, 600, 55.00, '15W无线快充'],
        ['SKU008', '鼠标垫', '办公周边', 500, '个', 5.00, 5000, 20.00, '防滑橡胶鼠标垫'],
        ['SKU009', '笔记本支架', '办公周边', 60, '个', 58.00, 400, 320.00, '铝合金散热支架'],
        ['SKU010', '桌面收纳盒', '办公周边', 150, '个', 28.00, 1200, 150.00, '多格分类收纳盒'],
    ];
    $stmt = $db->prepare("INSERT INTO moq_products (sku, name, category, moq, unit, price, stock, weight, remark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($products as $p) {
        $stmt->execute($p);
    }
    echo "初始化产品数据成功，共 " . count($products) . " 条记录\n";
}

echo "SQLite 数据库初始化完成: {$dbFile}\n";
