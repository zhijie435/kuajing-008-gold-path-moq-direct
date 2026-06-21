<?php

class ShippingController {

    private $shippingService;

    public function __construct() {
        $this->shippingService = new ShippingService();
    }

    public function index() {
        $params = [
            'page' => get_query_param('page', 1),
            'page_size' => get_query_param('page_size', 20),
            'keyword' => get_query_param('keyword', ''),
            'status' => get_query_param('status', null),
            'start_date' => get_query_param('start_date', ''),
            'end_date' => get_query_param('end_date', ''),
        ];

        $result = $this->shippingService->getShippingLabelList($params);
        json_success($result);
    }

    public function generate($orderId) {
        $result = $this->shippingService->generateShippingLabel((int)$orderId);
        json_success($result, '面单生成成功');
    }

    public function batchGenerate() {
        $data = get_input_data();
        $orderIds = $data['order_ids'] ?? [];

        $result = $this->shippingService->batchGenerateShippingLabels($orderIds);
        json_success($result, '批量生成完成');
    }

    public function printLabel($shippingId) {
        $result = $this->shippingService->printLabel((int)$shippingId);
        json_success($result, '打印成功');
    }

    public function batchPrint() {
        $data = get_input_data();
        $shippingIds = $data['shipping_ids'] ?? [];

        $result = $this->shippingService->batchPrintLabels($shippingIds);
        json_success($result, "已标记 {$result['count']} 张面单为打印状态");
    }

    public function markShipped($shippingId) {
        $result = $this->shippingService->markShipped((int)$shippingId);
        json_success($result, '发货成功');
    }

    public function batchMarkShipped() {
        $data = get_input_data();
        $shippingIds = $data['shipping_ids'] ?? [];

        $result = $this->shippingService->batchMarkShipped($shippingIds);
        json_success($result, "已标记 {$result['count']} 张面单为发货状态");
    }

    public function store() {
        json_error('请使用 /shipping/generate/{orderId} 接口生成面单');
    }
}
