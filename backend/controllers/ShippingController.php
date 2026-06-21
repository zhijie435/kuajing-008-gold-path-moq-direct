<?php
class ShippingController {
    private $db;
    private $table = 'moq_shipping_labels';
    private $itemTable = 'moq_shipping_items';
    private $orderTable = 'moq_orders';
    private $orderItemTable = 'moq_order_items';

    public function __construct() {
        $this->db = Database::getInstance();
    }

    private function loadShippingItems($shippingId) {
        return $this->db->fetchAll(
            "SELECT * FROM `{$this->itemTable}` WHERE shipping_id = ? ORDER BY id ASC",
            [$shippingId]
        );
    }

    private function formatLabel($label) {
        $label['items'] = $this->loadShippingItems($label['id']);
        return $label;
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
            $where[] = '(shipping_no LIKE ? OR order_no LIKE ? OR receiver_name LIKE ? OR receiver_phone LIKE ?)';
            $like = "%{$keyword}%";
            $params[] = $like;
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

        $list = array_map([$this, 'formatLabel'], $list);

        json_success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    private function createShippingLabel($orderId) {
        $order = $this->db->fetchOne("SELECT * FROM `{$this->orderTable}` WHERE id = ?", [$orderId]);
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        if ((int)$order['moq_checked'] !== 1) {
            return ['success' => false, 'message' => '订单未通过MOQ校验'];
        }

        if ((int)$order['status'] >= 20) {
            return ['success' => false, 'message' => '订单已生成面单'];
        }

        $existing = $this->db->fetchOne("SELECT id FROM `{$this->table}` WHERE order_id = ?", [$orderId]);
        if ($existing) {
            return ['success' => false, 'message' => '面单已存在'];
        }

        $orderItems = $this->db->fetchAll(
            "SELECT * FROM `{$this->orderItemTable}` WHERE order_id = ?",
            [$orderId]
        );

        if (count($orderItems) === 0) {
            return ['success' => false, 'message' => '订单没有商品'];
        }

        $this->db->beginTransaction();
        try {
            $shippingNo = generate_shipping_no();

            $shippingId = $this->db->insert($this->table, [
                'shipping_no' => $shippingNo,
                'order_id' => $orderId,
                'order_no' => $order['order_no'],
                'carrier' => CARRIER_DEFAULT,
                'receiver_name' => $order['receiver_name'],
                'receiver_phone' => $order['receiver_phone'],
                'receiver_address' => $order['receiver_address'],
                'total_weight' => round((float)$order['total_weight'], 2),
                'status' => 0,
            ]);

            foreach ($orderItems as $oi) {
                $this->db->insert($this->itemTable, [
                    'shipping_id' => $shippingId,
                    'sku' => $oi['sku'],
                    'name' => $oi['name'],
                    'quantity' => (int)$oi['quantity'],
                    'unit' => $oi['unit'],
                ]);
            }

            $this->db->update($this->orderTable, [
                'status' => 20,
                'shipping_id' => $shippingId,
            ], 'id = ?', [$orderId]);

            $this->db->commit();
            return [
                'success' => true,
                'shipping_id' => $shippingId,
                'shipping_no' => $shippingNo,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function generate($orderId) {
        $orderId = (int)$orderId;
        $result = $this->createShippingLabel($orderId);

        if (!$result['success']) {
            json_error($result['message']);
        }

        json_success($result, '面单生成成功');
    }

    public function batchGenerate() {
        $data = get_input_data();
        $orderIds = $data['order_ids'] ?? [];

        if (!is_array($orderIds) || count($orderIds) === 0) {
            json_error('请选择订单');
        }

        $successCount = 0;
        $failCount = 0;
        $results = [];

        foreach ($orderIds as $oid) {
            $oid = (int)$oid;
            $result = $this->createShippingLabel($oid);
            if ($result['success']) {
                $successCount++;
                $results[] = $result;
            } else {
                $failCount++;
            }
        }

        json_success([
            'success' => $successCount,
            'failed' => $failCount,
            'labels' => $results,
        ], '批量生成完成');
    }

    public function printLabel($shippingId) {
        $shippingId = (int)$shippingId;
        $label = $this->db->fetchOne("SELECT * FROM `{$this->table}` WHERE id = ?", [$shippingId]);
        if (!$label) json_error('面单不存在', 404);

        $now = date('Y-m-d H:i:s');
        $this->db->update($this->table, [
            'status' => max(1, (int)$label['status']) === 0 ? 1 : (int)$label['status'],
            'printed_at' => $label['printed_at'] ?? $now,
        ], 'id = ?', [$shippingId]);

        if ((int)$label['status'] < 2) {
            $this->db->update($this->table, ['status' => 1], 'id = ?', [$shippingId]);
        }

        json_success([
            'shipping_id' => $shippingId,
            'printed' => true,
        ], '打印成功');
    }

    public function batchPrint() {
        $data = get_input_data();
        $shippingIds = $data['shipping_ids'] ?? [];

        if (!is_array($shippingIds) || count($shippingIds) === 0) {
            json_error('请选择面单');
        }

        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach ($shippingIds as $sid) {
            $sid = (int)$sid;
            $label = $this->db->fetchOne("SELECT id, status, printed_at FROM `{$this->table}` WHERE id = ?", [$sid]);
            if (!$label) continue;

            $this->db->update($this->table, [
                'status' => (int)$label['status'] === 0 ? 1 : (int)$label['status'],
                'printed_at' => $label['printed_at'] ?? $now,
            ], 'id = ?', [$sid]);
            $count++;
        }

        json_success(['count' => $count], "已标记 {$count} 张面单为打印状态");
    }

    public function store() {
        json_error('请使用 /shipping/generate/{orderId} 接口生成面单');
    }
}
