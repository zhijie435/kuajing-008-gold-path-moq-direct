<?php
class OrderController {
    private $db;
    private $table = 'moq_orders';
    private $itemTable = 'moq_order_items';

    public function __construct() {
        $this->db = Database::getInstance();
    }

    private function loadOrderItems($orderId) {
        return $this->db->fetchAll(
            "SELECT * FROM `{$this->itemTable}` WHERE order_id = ? ORDER BY id ASC",
            [$orderId]
        );
    }

    private function formatOrder($order) {
        $order['status_text'] = get_order_status_text($order['status']);
        $order['items'] = $this->loadOrderItems($order['id']);
        return $order;
    }

    private function getOrderById($orderId) {
        $row = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$orderId]);
        if (!$row) return null;
        return $this->formatOrder($row);
    }

    public function index() {
        $page = max(1, (int)get_query_param('page', 1));
        $pageSize = max(1, (int)get_query_param('page_size', 20));
        $keyword = trim((string)get_query_param('keyword', ''));
        $status = get_query_param('status', null);
        $startDate = trim((string)get_query_param('start_date', ''));
        $endDate = trim((string)get_query_param('end_date', ''));
        $offset = ($page - 1) * $pageSize;

        $where = ['1=1'];
        $params = [];

        if ($keyword) {
            $where[] = '(order_no LIKE ? OR receiver_name LIKE ? OR receiver_phone LIKE ?)';
            $like = "%{$keyword}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $params[] = (int)$status;
        }

        if ($startDate) {
            $where[] = 'DATE(created_at) >= ?';
            $params[] = $startDate;
        }
        if ($endDate) {
            $where[] = 'DATE(created_at) <= ?';
            $params[] = $endDate;
        }

        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) AS total FROM `{$this->table}` WHERE {$whereSql}";
        $total = (int)($this->db->fetchOne($countSql, $params)['total'] ?? 0);

        $sql = "SELECT * FROM `{$this->table}` WHERE {$whereSql} ORDER BY id DESC LIMIT {$offset}, {$pageSize}";
        $list = $this->db->fetchAll($sql, $params);

        $list = array_map([$this, 'formatOrder'], $list);

        json_success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    public function show($id) {
        $row = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$id]);
        if (!$row) json_error('订单不存在', 404);
        json_success($this->formatOrder($row));
    }

    public function store() {
        $data = get_input_data();

        $receiverName = trim($data['receiver_name'] ?? '');
        $receiverPhone = trim($data['receiver_phone'] ?? '');
        $receiverAddress = trim($data['receiver_address'] ?? '');
        $items = $data['items'] ?? [];

        if (!$receiverName || !$receiverPhone || !$receiverAddress) {
            json_error('收件信息不完整');
        }
        if (!is_array($items) || count($items) === 0) {
            json_error('请添加商品');
        }

        $moqResult = $this->validateItemsMoq($items);
        if (!$moqResult['passed']) {
            json_error('订单包含未达到起订量的商品：' . $moqResult['message']);
        }

        $this->db->beginTransaction();
        try {
            $orderNo = generate_order_no();
            $totalQty = 0;
            $totalAmount = 0;
            $totalWeight = 0;

            $orderId = $this->db->insert($this->table, [
                'order_no' => $orderNo,
                'receiver_name' => $receiverName,
                'receiver_phone' => $receiverPhone,
                'receiver_address' => $receiverAddress,
                'remark' => trim($data['remark'] ?? ''),
                'total_quantity' => 0,
                'total_amount' => 0,
                'total_weight' => 0,
                'status' => 0,
                'moq_checked' => 1,
                'moq_fail_reason' => '',
            ]);

            $productIds = [];
            foreach ($items as $item) {
                if (!empty($item['product_id'])) {
                    $productIds[] = (int)$item['product_id'];
                }
            }

            $productMap = [];
            if ($productIds) {
                $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                $products = $this->db->fetchAll(
                    "SELECT * FROM moq_products WHERE id IN ({$placeholders})",
                    $productIds
                );
                foreach ($products as $p) {
                    $productMap[$p['id']] = $p;
                }
            }

            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $quantity = max(1, (int)($item['quantity'] ?? 1));
                $moq = (int)($item['moq'] ?? 1);
                $price = round((float)($item['price'] ?? 0), 2);
                $weight = round((float)($item['weight'] ?? 0), 2);
                $sku = trim($item['sku'] ?? '');
                $name = trim($item['name'] ?? '');
                $unit = trim($item['unit'] ?? '件');

                if (isset($productMap[$productId])) {
                    $p = $productMap[$productId];
                    if (!$sku) $sku = $p['sku'];
                    if (!$name) $name = $p['name'];
                    if ($moq <= 0) $moq = (int)$p['moq'];
                    if ($unit === '') $unit = $p['unit'];
                    if ($price <= 0) $price = round((float)$p['price'], 2);
                    if ($weight <= 0) $weight = round((float)$p['weight'], 2);
                }

                $moqPassed = $quantity >= $moq ? 1 : 0;

                $this->db->insert($this->itemTable, [
                    'order_id' => $orderId,
                    'product_id' => $productId,
                    'sku' => $sku,
                    'name' => $name,
                    'moq' => $moq,
                    'unit' => $unit,
                    'quantity' => $quantity,
                    'price' => $price,
                    'weight' => $weight,
                    'moq_passed' => $moqPassed,
                ]);

                $totalQty += $quantity;
                $totalAmount += $quantity * $price;
                $totalWeight += $quantity * $weight;
            }

            $this->db->update($this->table, [
                'total_quantity' => $totalQty,
                'total_amount' => round($totalAmount, 2),
                'total_weight' => round($totalWeight, 2),
            ], 'id = ?', [$orderId]);

            $this->db->commit();
            json_success(['id' => $orderId, 'order_no' => $orderNo], '订单创建成功');
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update($id) {
        $row = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$id]);
        if (!$row) json_error('订单不存在', 404);

        $data = get_input_data();
        $allowed = ['receiver_name', 'receiver_phone', 'receiver_address', 'remark', 'status'];
        $update = [];
        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $update[$field] = $data[$field];
            }
        }

        if ($update) {
            $this->db->update($this->table, $update, 'id = ?', [$id]);
        }
        json_success(null, '更新成功');
    }

    public function destroy($id) {
        $row = $this->db->fetchOne("SELECT id FROM `{$this->table}` WHERE id = ?", [$id]);
        if (!$row) json_error('订单不存在', 404);

        $this->db->update($this->table, ['status' => 40], 'id = ?', [$id]);
        json_success(null, '删除成功');
    }

    private function validateItemsMoq($items) {
        $results = [];
        $allPassed = true;
        $failedItems = [];

        foreach ($items as $item) {
            $id = (int)($item['id'] ?? 0);
            $sku = trim($item['sku'] ?? '');
            $name = trim($item['name'] ?? '');
            $quantity = max(0, (int)($item['quantity'] ?? 0));
            $moq = max(1, (int)($item['moq'] ?? 1));
            $passed = $quantity >= $moq;

            if (!$passed) {
                $allPassed = false;
                $failedItems[] = "{$sku}($name) 需要起订量 {$moq}，当前 {$quantity}";
            }

            $results[] = [
                'id' => $id,
                'sku' => $sku,
                'name' => $name,
                'quantity' => $quantity,
                'moq' => $moq,
                'passed' => $passed,
            ];
        }

        return [
            'passed' => $allPassed,
            'items' => $results,
            'failed_list' => $failedItems,
            'message' => $allPassed ? '所有商品均满足起订量要求' : implode('；', $failedItems),
        ];
    }

    public function checkMoq() {
        $data = get_input_data();
        $orderId = (int)($data['order_id'] ?? 0);
        $items = $data['items'] ?? null;

        if ($orderId > 0) {
            $order = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$orderId]);
            if (!$order) json_error('订单不存在', 404);
            $items = $this->loadOrderItems($orderId);
        }

        if (!is_array($items) || count($items) === 0) {
            json_error('没有商品需要校验');
        }

        $result = $this->validateItemsMoq($items);

        $orderData = null;

        if ($orderId > 0) {
            $this->db->beginTransaction();
            try {
                $currentStatus = (int)$order['status'];
                $newStatus = $result['passed']
                    ? ($currentStatus < 10 ? 10 : $currentStatus)
                    : ($currentStatus >= 20 ? $currentStatus : 0);

                $this->db->update($this->table, [
                    'moq_checked' => $result['passed'] ? 1 : 2,
                    'moq_fail_reason' => $result['passed'] ? '' : $result['message'],
                    'status' => $newStatus,
                ], 'id = ?', [$orderId]);

                foreach ($result['items'] as $ri) {
                    if (!empty($ri['id'])) {
                        $this->db->update($this->itemTable, [
                            'moq_passed' => $ri['passed'] ? 1 : 0,
                            'quantity' => $ri['quantity'],
                            'moq' => $ri['moq'],
                        ], 'id = ? AND order_id = ?', [$ri['id'], $orderId]);
                    }
                }
                $this->db->commit();

                $orderData = $this->getOrderById($orderId);
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
        }

        json_success([
            'passed' => $result['passed'],
            'message' => $result['message'],
            'items' => $result['items'],
            'order' => $orderData,
        ]);
    }

    public function batchCheckMoq() {
        $data = get_input_data();
        $orderIds = $data['order_ids'] ?? [];

        if (!is_array($orderIds) || count($orderIds) === 0) {
            json_error('请选择订单');
        }

        $passedCount = 0;
        $failedCount = 0;
        $updatedOrders = [];

        foreach ($orderIds as $oid) {
            $oid = (int)$oid;
            $order = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$oid]);
            if (!$order) continue;

            $items = $this->loadOrderItems($oid);
            $result = $this->validateItemsMoq($items);

            if ($result['passed']) {
                $passedCount++;
            } else {
                $failedCount++;
            }

            $currentStatus = (int)$order['status'];
            $newStatus = $result['passed']
                ? ($currentStatus < 10 ? 10 : $currentStatus)
                : ($currentStatus >= 20 ? $currentStatus : 0);

            $this->db->update($this->table, [
                'moq_checked' => $result['passed'] ? 1 : 2,
                'moq_fail_reason' => $result['passed'] ? '' : $result['message'],
                'status' => $newStatus,
            ], 'id = ?', [$oid]);

            foreach ($result['items'] as $ri) {
                if (!empty($ri['id'])) {
                    $this->db->update($this->itemTable, [
                        'moq_passed' => $ri['passed'] ? 1 : 0,
                    ], 'id = ? AND order_id = ?', [$ri['id'], $oid]);
                }
            }

            $updatedOrders[] = $this->getOrderById($oid);
        }

        json_success([
            'passed' => $passedCount,
            'failed' => $failedCount,
            'orders' => $updatedOrders,
        ], '批量校验完成');
    }

    public function review($id) {
        $row = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$id]);
        if (!$row) json_error('订单不存在', 404);

        if ((int)$row['moq_checked'] !== 1) {
            json_error('订单未通过MOQ校验，无法审核');
        }

        if ((int)$row['status'] >= 15) {
            json_error('订单已审核，无需重复操作');
        }

        $items = $this->loadOrderItems($id);
        $allItemsPassed = true;
        $failedSkuList = [];
        foreach ($items as $it) {
            $qty = (int)$it['quantity'];
            $moq = (int)$it['moq'];
            $passed = $qty >= $moq;
            if (!$passed) {
                $allItemsPassed = false;
                $failedSkuList[] = "{$it['sku']}({$it['name']})";
            }
        }

        if (!$allItemsPassed) {
            json_error('商品明细未满足起订量：' . implode('，', $failedSkuList) . '，请先校验');
        }

        $data = get_input_data();
        $approved = (bool)($data['approved'] ?? true);
        $reviewRemark = trim($data['review_remark'] ?? '');

        $this->db->beginTransaction();
        try {
            if ($approved) {
                $this->db->update($this->table, [
                    'status' => 15,
                    'moq_checked' => 1,
                    'moq_fail_reason' => '',
                    'review_remark' => $reviewRemark,
                    'reviewed_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$id]);
            } else {
                $this->db->update($this->table, [
                    'status' => 10,
                    'review_remark' => $reviewRemark,
                ], 'id = ?', [$id]);
            }

            $this->db->commit();
            $order = $this->getOrderById($id);
            json_success($order, $approved ? '审核通过' : '审核驳回');
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function batchReview() {
        $data = get_input_data();
        $orderIds = $data['order_ids'] ?? [];
        $approved = (bool)($data['approved'] ?? true);
        $reviewRemark = trim($data['review_remark'] ?? '');

        if (!is_array($orderIds) || count($orderIds) === 0) {
            json_error('请选择订单');
        }

        $successCount = 0;
        $failCount = 0;
        $updatedOrders = [];

        foreach ($orderIds as $oid) {
            $oid = (int)$oid;
            $order = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$oid]);
            if (!$order) {
                $failCount++;
                continue;
            }

            if ((int)$order['moq_checked'] !== 1 || (int)$order['status'] >= 15) {
                $failCount++;
                continue;
            }

            $items = $this->loadOrderItems($oid);
            $allItemsPassed = true;
            foreach ($items as $it) {
                if ((int)$it['quantity'] < (int)$it['moq']) {
                    $allItemsPassed = false;
                    break;
                }
            }
            if (!$allItemsPassed) {
                $failCount++;
                continue;
            }

            if ($approved) {
                $this->db->update($this->table, [
                    'status' => 15,
                    'moq_checked' => 1,
                    'moq_fail_reason' => '',
                    'review_remark' => $reviewRemark,
                    'reviewed_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$oid]);
            } else {
                $this->db->update($this->table, [
                    'status' => 10,
                    'review_remark' => $reviewRemark,
                ], 'id = ?', [$oid]);
            }

            $successCount++;
            $updatedOrders[] = $this->getOrderById($oid);
        }

        json_success([
            'success' => $successCount,
            'failed' => $failCount,
            'orders' => $updatedOrders,
        ], $approved ? '批量审核通过完成' : '批量审核驳回完成');
    }
}
