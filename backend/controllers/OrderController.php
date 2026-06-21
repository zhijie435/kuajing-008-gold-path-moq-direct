<?php

class OrderController {

    private $orderService;
    private $moqService;

    public function __construct() {
        $this->orderService = new OrderService();
        $this->moqService = new MoqService();
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

        $result = $this->orderService->getOrderList($params);
        json_success($result);
    }

    public function show($id) {
        $result = $this->orderService->getOrderDetail((int)$id);
        json_success($result);
    }

    public function store() {
        $data = get_input_data();
        $result = $this->orderService->createOrder($data);
        json_success($result, '订单创建成功');
    }

    public function update($id) {
        $data = get_input_data();
        $this->orderService->updateOrder((int)$id, $data);
        json_success(null, '更新成功');
    }

    public function destroy($id) {
        $this->orderService->deleteOrder((int)$id);
        json_success(null, '删除成功');
    }

    public function checkMoq() {
        $data = get_input_data();
        $orderId = (int)($data['order_id'] ?? 0);
        $items = $data['items'] ?? null;

        $result = $this->moqService->checkOrderMoq($orderId, $items);
        json_success($result);
    }

    public function batchCheckMoq() {
        $data = get_input_data();
        $orderIds = $data['order_ids'] ?? [];

        if (!is_array($orderIds) || count($orderIds) === 0) {
            json_error('请选择订单', Constants::ERROR_CODE_PARAM_ERROR);
        }

        $result = $this->moqService->batchCheckMoq($orderIds);
        json_success($result, '批量校验完成');
    }

    public function review($id) {
        $data = get_input_data();
        $result = $this->orderService->reviewOrder((int)$id, $data);
        json_success($result['order'], $result['message']);
    }

    public function batchReview() {
        $data = get_input_data();
        $orderIds = $data['order_ids'] ?? [];

        $result = $this->orderService->batchReviewOrders($orderIds, $data);
        $message = !empty($data['approved']) ? '批量审核通过完成' : '批量审核驳回完成';
        json_success($result, $message);
    }
}
