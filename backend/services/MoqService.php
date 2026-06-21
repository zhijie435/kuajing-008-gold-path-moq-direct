<?php

class MoqService {

    private $db;
    private $orderTable = 'moq_orders';
    private $orderItemTable = 'moq_order_items';

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function validateItems($items) {
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

    public function validateOrderItems($orderId) {
        $orderItems = $this->db->fetchAll(
            "SELECT * FROM `{$this->orderItemTable}` WHERE order_id = ?",
            [$orderId]
        );
        return $this->validateItems($orderItems);
    }

    public function checkOrderMoq($orderId, $items = null) {
        $order = $this->db->fetchOne("SELECT * FROM `{$this->orderTable}` WHERE id = ?", [$orderId]);
        if (!$order) {
            throw new BusinessException(
                '订单不存在',
                Constants::ERROR_CODE_ORDER_NOT_FOUND,
                ['order_id' => $orderId],
                false,
                false
            );
        }

        if ($items === null) {
            $items = $this->loadOrderItems($orderId);
        }

        if (!is_array($items) || count($items) === 0) {
            throw new BusinessException(
                '没有商品需要校验',
                Constants::ERROR_CODE_ORDER_NO_ITEMS,
                ['order_id' => $orderId],
                false,
                false
            );
        }

        $result = $this->validateItems($items);

        $this->db->beginTransaction();
        try {
            $currentStatus = (int)$order['status'];
            $newStatus = $result['passed']
                ? ($currentStatus < Constants::ORDER_STATUS_MOQ_PASSED ? Constants::ORDER_STATUS_MOQ_PASSED : $currentStatus)
                : ($currentStatus >= Constants::ORDER_STATUS_LABEL_GENERATED ? $currentStatus : Constants::ORDER_STATUS_PENDING);

            $this->db->update($this->orderTable, [
                'moq_checked' => $result['passed'] ? Constants::MOQ_CHECK_PASSED : Constants::MOQ_CHECK_FAILED,
                'moq_fail_reason' => $result['passed'] ? '' : $result['message'],
                'status' => $newStatus,
            ], 'id = ?', [$orderId]);

            foreach ($result['items'] as $ri) {
                if (!empty($ri['id'])) {
                    $this->db->update($this->orderItemTable, [
                        'moq_passed' => $ri['passed'] ? 1 : 0,
                        'quantity' => $ri['quantity'],
                        'moq' => $ri['moq'],
                    ], 'id = ? AND order_id = ?', [$ri['id'], $orderId]);
                }
            }
            $this->db->commit();

            $orderData = $this->getOrderById($orderId);

            return [
                'passed' => $result['passed'],
                'message' => $result['message'],
                'items' => $result['items'],
                'order' => $orderData,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            throw new BusinessException(
                'MOQ校验失败，数据已自动回滚：' . $e->getMessage(),
                Constants::ERROR_CODE_MOQ_CHECK_FAILED,
                ['order_id' => $orderId],
                true,
                true
            );
        }
    }

    public function batchCheckMoq($orderIds) {
        $passedCount = 0;
        $failedCount = 0;
        $updatedOrders = [];

        foreach ($orderIds as $oid) {
            $oid = (int)$oid;
            try {
                $result = $this->checkOrderMoq($oid);
                if ($result['passed']) {
                    $passedCount++;
                } else {
                    $failedCount++;
                }
                $updatedOrders[] = $result['order'];
            } catch (BusinessException $e) {
                $failedCount++;
                continue;
            }
        }

        return [
            'passed' => $passedCount,
            'failed' => $failedCount,
            'orders' => $updatedOrders,
        ];
    }

    public function ensureMoqChecked($order) {
        $moqChecked = (int)($order['moq_checked'] ?? 0);

        if ($moqChecked === Constants::MOQ_CHECK_FAILED) {
            throw new BusinessException(
                '订单起订量校验未通过，请先调整商品数量后再生成面单',
                Constants::ERROR_CODE_MOQ_CHECK_FAILED,
                [
                    'order_id' => $order['id'],
                    'order_no' => $order['order_no'],
                    'moq_fail_reason' => $order['moq_fail_reason'] ?? '',
                ],
                false,
                false
            );
        }

        if ($moqChecked !== Constants::MOQ_CHECK_PASSED) {
            throw new BusinessException(
                '订单未完成起订量校验，请先执行"校验MOQ"',
                Constants::ERROR_CODE_MOQ_NOT_CHECKED,
                [
                    'order_id' => $order['id'],
                    'order_no' => $order['order_no'],
                ],
                false,
                false
            );
        }

        return true;
    }

    public function ensureAllItemsPassed($orderId, $orderItems = null) {
        if ($orderItems === null) {
            $orderItems = $this->db->fetchAll(
                "SELECT * FROM `{$this->orderItemTable}` WHERE order_id = ?",
                [$orderId]
            );
        }

        foreach ($orderItems as $oi) {
            if ((int)$oi['moq_passed'] !== 1) {
                throw new BusinessException(
                    "商品 {$oi['sku']}({$oi['name']}) 明细未满足起订量（MOQ:{$oi['moq']}, 当前:{$oi['quantity']}），请重新校验",
                    Constants::ERROR_CODE_MOQ_ITEM_FAILED,
                    [
                        'order_id' => $orderId,
                        'sku' => $oi['sku'],
                        'moq' => $oi['moq'],
                        'quantity' => $oi['quantity'],
                    ],
                    false,
                    false
                );
            }
        }

        return true;
    }

    public function checkOrderReviewReady($order) {
        if ((int)$order['moq_checked'] !== Constants::MOQ_CHECK_PASSED) {
            throw new BusinessException(
                '订单未通过MOQ校验，无法审核',
                Constants::ERROR_CODE_MOQ_NOT_CHECKED,
                ['order_id' => $order['id']],
                false,
                false
            );
        }

        if ((int)$order['status'] >= Constants::ORDER_STATUS_REVIEWED) {
            throw new BusinessException(
                '订单已审核，无需重复操作',
                Constants::ERROR_CODE_ORDER_ALREADY_REVIEWED,
                ['order_id' => $order['id']],
                false,
                false
            );
        }

        $items = $this->loadOrderItems($order['id']);
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
            throw new BusinessException(
                '商品明细未满足起订量：' . implode('，', $failedSkuList) . '，请先校验',
                Constants::ERROR_CODE_MOQ_ITEM_FAILED,
                [
                    'order_id' => $order['id'],
                    'failed_skus' => $failedSkuList,
                ],
                false,
                false
            );
        }

        return true;
    }

    private function loadOrderItems($orderId) {
        return $this->db->fetchAll(
            "SELECT * FROM `{$this->orderItemTable}` WHERE order_id = ? ORDER BY id ASC",
            [$orderId]
        );
    }

    private function getOrderById($orderId) {
        $row = $this->db->fetchOne("SELECT * FROM `{$this->orderTable}` WHERE id = ?", [$orderId]);
        if (!$row) return null;
        return $this->formatOrder($row);
    }

    private function formatOrder($order) {
        $order['status_text'] = Constants::getOrderStatusText($order['status']);
        $order['moq_check_text'] = Constants::getMoqCheckText($order['moq_checked']);
        $order['items'] = $this->loadOrderItems($order['id']);
        return $order;
    }
}
