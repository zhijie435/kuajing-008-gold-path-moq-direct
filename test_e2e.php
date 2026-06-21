<?php
$baseUrl = 'http://localhost:8088/api';

function request($method, $path, $data = null) {
    global $baseUrl;
    $ch = curl_init($baseUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($result, true)];
}

echo "=== MOQ直发打单链路 端到端测试 ===\n\n";

echo "[1] 获取产品列表...\n";
$r = request('GET', '/products?page_size=3');
echo "  状态: {$r['code']}, 产品数: " . count($r['body']['data']['list']) . "\n";
$products = $r['body']['data']['list'];
print_r($products);
echo "\n";

echo "[2] 创建订单（MOQ满足的商品）...\n";
$orderData = [
    'receiver_name' => '张三',
    'receiver_phone' => '13800138000',
    'receiver_address' => '广东省深圳市南山区科技园100号',
    'remark' => '测试订单-MOQ满足',
    'items' => [
        [
            'product_id' => $products[0]['id'],
            'sku' => $products[0]['sku'],
            'name' => $products[0]['name'],
            'moq' => $products[0]['moq'],
            'unit' => $products[0]['unit'],
            'quantity' => $products[0]['moq'] + 10,
            'price' => $products[0]['price'],
            'weight' => $products[0]['weight'],
        ],
        [
            'product_id' => $products[1]['id'],
            'sku' => $products[1]['sku'],
            'name' => $products[1]['name'],
            'moq' => $products[1]['moq'],
            'unit' => $products[1]['unit'],
            'quantity' => $products[1]['moq'],
            'price' => $products[1]['price'],
            'weight' => $products[1]['weight'],
        ],
    ],
];
$r = request('POST', '/orders', $orderData);
echo "  状态: {$r['code']}, 响应: " . json_encode($r['body'], JSON_UNESCAPED_UNICODE) . "\n";
$order1Id = $r['body']['data']['id'];
echo "\n";

echo "[3] 创建订单（MOQ不满足的商品）...\n";
$orderData2 = [
    'receiver_name' => '李四',
    'receiver_phone' => '13900139000',
    'receiver_address' => '北京市朝阳区xxx路1号',
    'remark' => '测试订单-MOQ不足',
    'items' => [
        [
            'product_id' => $products[0]['id'],
            'sku' => $products[0]['sku'],
            'name' => $products[0]['name'],
            'moq' => $products[0]['moq'],
            'unit' => $products[0]['unit'],
            'quantity' => 5,
            'price' => $products[0]['price'],
            'weight' => $products[0]['weight'],
        ],
    ],
];
$r = request('POST', '/orders', $orderData2);
echo "  状态: {$r['code']}, 订单ID: " . ($r['body']['data']['id'] ?? 'none') . "\n";
$order2Id = $r['body']['data']['id'];
echo "\n";

echo "[4] MOQ校验 - 满足MOQ的订单...\n";
$r = request('POST', '/orders/check-moq', ['order_id' => $order1Id]);
echo "  状态: {$r['code']}\n";
echo "  校验结果: " . ($r['body']['data']['passed'] ? '通过' : '不通过') . "\n";
echo "  消息: {$r['body']['data']['message']}\n";
$passed = $r['body']['data']['passed'];
echo "  ✅ " . ($passed ? "测试通过：MOQ校验正确识别满足起订量" : "❌ 测试失败") . "\n\n";

echo "[5] MOQ校验 - 不满足MOQ的订单...\n";
$r = request('POST', '/orders/check-moq', ['order_id' => $order2Id]);
echo "  状态: {$r['code']}\n";
echo "  校验结果: " . ($r['body']['data']['passed'] ? '通过' : '不通过') . "\n";
echo "  消息: {$r['body']['data']['message']}\n";
$notPassed = !$r['body']['data']['passed'];
echo "  ✅ " . ($notPassed ? "测试通过：MOQ校验正确识别不满足起订量" : "❌ 测试失败") . "\n\n";

echo "[6] 面单生成 - 已通过MOQ的订单...\n";
$r = request('POST', "/shipping/generate/{$order1Id}");
echo "  状态: {$r['code']}, 响应: " . json_encode($r['body'], JSON_UNESCAPED_UNICODE) . "\n";
$shippingOk = $r['body']['code'] === 0;
echo "  ✅ " . ($shippingOk ? "测试通过：面单生成成功" : "❌ 测试失败") . "\n\n";

echo "[7] 面单生成 - 未通过MOQ的订单（应拒绝）...\n";
$r = request('POST', "/shipping/generate/{$order2Id}");
echo "  状态: {$r['code']}, 响应: " . json_encode($r['body'], JSON_UNESCAPED_UNICODE) . "\n";
$shippingBlocked = $r['body']['code'] !== 0;
echo "  ✅ " . ($shippingBlocked ? "测试通过：未通过MOQ的订单正确拒绝生成面单" : "❌ 测试失败") . "\n\n";

echo "[8] 查询面单列表...\n";
$r = request('GET', '/shipping');
echo "  状态: {$r['code']}, 面单数: " . count($r['body']['data']['list']) . "\n";
echo "  面单数据:\n";
foreach ($r['body']['data']['list'] as $label) {
    echo "    - 运单号: {$label['shipping_no']}, 订单: {$label['order_no']}, 收件人: {$label['receiver_name']}\n";
}
echo "\n";

echo "[9] 数据概览统计...\n";
$r = request('GET', '/dashboard/stats');
echo "  状态: {$r['code']}, 数据: " . json_encode($r['body']['data'], JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== 测试总结 ===\n";
$allPassed = $passed && $notPassed && $shippingOk && $shippingBlocked;
echo ($allPassed ? "✅ 所有核心链路测试通过！" : "❌ 存在测试失败，请检查！") . "\n";
