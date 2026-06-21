<?php
class DashboardController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function stats() {
        $today = date('Y-m-d');

        $todayOrders = (int)($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM moq_orders WHERE DATE(created_at) = ?",
            [$today]
        )['c'] ?? 0);

        $pendingShipping = (int)($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM moq_orders WHERE moq_checked = 1 AND status < 20"
        )['c'] ?? 0);

        $shipped = (int)($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM moq_shipping_labels WHERE status >= 2"
        )['c'] ?? 0);

        $totalProducts = (int)($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM moq_products WHERE status = 1"
        )['c'] ?? 0);

        json_success([
            'todayOrders' => $todayOrders,
            'pendingShipping' => $pendingShipping,
            'shipped' => $shipped,
            'totalProducts' => $totalProducts,
        ]);
    }
}
