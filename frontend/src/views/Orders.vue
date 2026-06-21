<template>
  <div class="page-container">
    <div class="page-header">
      <h2 class="page-title">订单管理</h2>
    </div>

    <div class="card">
      <div class="toolbar">
        <div class="search-form">
          <el-input v-model="searchForm.keyword" placeholder="订单号/收件人/电话" clearable style="width: 240px" />
          <el-select v-model="searchForm.status" placeholder="订单状态" clearable style="width: 140px">
            <el-option label="待审核" :value="0" />
            <el-option label="MOQ已通过" :value="10" />
            <el-option label="已审核" :value="15" />
            <el-option label="已生成面单" :value="20" />
            <el-option label="已发货" :value="30" />
            <el-option label="已取消" :value="40" />
          </el-select>
          <el-date-picker
            v-model="searchForm.date_range"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="YYYY-MM-DD"
          />
          <el-button type="primary" @click="loadList">
            <el-icon><Search /></el-icon> 搜索
          </el-button>
          <el-button @click="resetSearch">
            <el-icon><Refresh /></el-icon> 重置
          </el-button>
        </div>
        <div class="flex-gap">
          <el-button type="primary" @click="$router.push('/orders/create')">
            <el-icon><Plus /></el-icon> 新建订单
          </el-button>
          <el-button type="success" :disabled="selectedOrders.length === 0" @click="batchCheckMoq">
            <el-icon><CircleCheck /></el-icon> 批量校验MOQ
          </el-button>
          <el-button type="primary" :disabled="selectedOrders.length === 0" @click="openBatchReview(true)">
            <el-icon><Check /></el-icon> 批量审核通过
          </el-button>
          <el-button type="danger" :disabled="selectedOrders.length === 0" @click="openBatchReview(false)">
            <el-icon><Close /></el-icon> 批量审核驳回
          </el-button>
          <el-button type="warning" :disabled="selectedOrders.length === 0" @click="batchGenerateShipping">
            <el-icon><Printer /></el-icon> 批量打单
          </el-button>
        </div>
      </div>

      <el-table
        ref="tableRef"
        :data="tableData"
        v-loading="loading"
        stripe
        row-key="id"
        @selection-change="handleSelectionChange"
      >
        <el-table-column type="selection" width="50" />
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="order_no" label="订单号" width="160" />
        <el-table-column label="收件信息" min-width="200">
          <template #default="{ row }">
            <div>{{ row.receiver_name }} {{ row.receiver_phone }}</div>
            <div style="color: #909399; font-size: 12px">{{ row.receiver_address }}</div>
          </template>
        </el-table-column>
        <el-table-column label="商品信息" min-width="200">
          <template #default="{ row }">
            <div v-for="(item, idx) in row.items" :key="idx" style="font-size: 13px">
              {{ item.sku }} x{{ item.quantity }}
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="total_quantity" label="总数量" width="80" />
        <el-table-column prop="status_text" label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="getStatusType(row.status)">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="MOQ校验" width="130">
          <template #default="{ row }">
            <el-tag :type="getOrderMoqTagType(row)">{{ getOrderMoqText(row) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="170" />
        <el-table-column label="操作" width="300" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link @click="handleCheckMoq(row)" v-if="row.status !== 40 && row.status < 15 && !isOrderMoqPassed(row)">校验MOQ</el-button>
            <el-button type="primary" link @click="openReview(row, true)" v-if="row.status === 10">审核通过</el-button>
            <el-button type="danger" link @click="openReview(row, false)" v-if="row.status === 10">审核驳回</el-button>
            <el-button type="warning" link @click="handleGenerateShipping(row)" v-if="row.status === 15">生成面单</el-button>
            <el-button type="success" link @click="viewDetail(row)">详情</el-button>
            <el-button type="danger" link @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="mt-16">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.page_size"
          :page-sizes="[10, 20, 50, 100]"
          :total="pagination.total"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="loadList"
          @current-change="loadList"
        />
      </div>
    </div>

    <el-dialog v-model="detailVisible" title="订单详情" width="700px">
      <div v-if="currentOrder" style="padding: 0 10px">
        <el-alert
          v-if="currentOrder.moq_checked === 2"
          :title="'MOQ校验未通过：' + (currentOrder.moq_fail_reason || '部分商品未达到起订量')"
          type="error"
          :closable="false"
          show-icon
          class="mb-16"
        />
        <el-descriptions :column="2" border>
          <el-descriptions-item label="订单号">{{ currentOrder.order_no }}</el-descriptions-item>
          <el-descriptions-item label="订单状态">
            <el-tag :type="getStatusType(currentOrder.status)">{{ currentOrder.status_text }}</el-tag>
          </el-descriptions-item>
          <el-descriptions-item label="收件人">{{ currentOrder.receiver_name }}</el-descriptions-item>
          <el-descriptions-item label="联系电话">{{ currentOrder.receiver_phone }}</el-descriptions-item>
          <el-descriptions-item label="收件地址" :span="2">{{ currentOrder.receiver_address }}</el-descriptions-item>
          <el-descriptions-item label="备注" :span="2">{{ currentOrder.remark || '-' }}</el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ currentOrder.created_at }}</el-descriptions-item>
          <el-descriptions-item label="MOQ校验">
            <div class="flex-gap" style="align-items:center">
              <el-tag :type="getOrderMoqTagType(currentOrder)">{{ getOrderMoqText(currentOrder) }}</el-tag>
              <el-button
                type="primary"
                size="small"
                link
                @click="checkMoqFromDetail"
                v-if="currentOrder.status !== 40 && currentOrder.status < 15 && !isOrderMoqPassed(currentOrder)"
              >
                <el-icon><Refresh /></el-icon> 重新校验
              </el-button>
            </div>
          </el-descriptions-item>
          <el-descriptions-item label="审核时间" v-if="currentOrder.reviewed_at">{{ currentOrder.reviewed_at }}</el-descriptions-item>
          <el-descriptions-item label="审核备注" :span="2" v-if="currentOrder.review_remark">{{ currentOrder.review_remark }}</el-descriptions-item>
        </el-descriptions>
        <el-divider>商品明细</el-divider>
        <el-table :data="currentOrder?.items || []" border>
          <el-table-column prop="sku" label="SKU" />
          <el-table-column prop="name" label="产品名称" />
          <el-table-column prop="quantity" label="数量" width="100">
            <template #default="{ row }">
              <span :style="{ color: !isItemMoqPassed(row) ? '#F56C6C' : '' }">{{ row.quantity }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="moq" label="MOQ" width="100" />
          <el-table-column label="差值" width="90">
            <template #default="{ row }">
              <span :style="{ color: !isItemMoqPassed(row) ? '#F56C6C' : '#67C23A' }">
                {{ row.quantity >= row.moq ? '+' : '' }}{{ row.quantity - row.moq }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="MOQ状态" width="120">
            <template #default="{ row }">
              <el-tag v-if="isItemMoqPassed(row)" type="success">满足</el-tag>
              <el-tag v-else type="danger">不满足</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="数据库状态" width="120" v-if="currentOrder?.items?.some(i => Object.prototype.hasOwnProperty.call(i, 'moq_passed'))">
            <template #default="{ row }">
              <el-tag v-if="row.moq_passed === 1 || row.moq_passed === true" type="success">DB:通过</el-tag>
              <el-tag v-else-if="row.moq_passed === 0 || row.moq_passed === false" type="danger">DB:未通过</el-tag>
              <el-tag v-else type="info">DB:未写入</el-tag>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </el-dialog>

    <el-dialog v-model="reviewVisible" :title="reviewForm.approved ? '审核通过' : '审核驳回'" width="500px">
      <el-form :model="reviewForm" label-width="80px">
        <el-form-item label="审核备注">
          <el-input v-model="reviewForm.review_remark" type="textarea" :rows="3" placeholder="请输入审核备注（可选）" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="reviewVisible = false">取消</el-button>
        <el-button :type="reviewForm.approved ? 'primary' : 'danger'" @click="submitReview">
          确认{{ reviewForm.approved ? '通过' : '驳回' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, h } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { orderApi, shippingApi } from '@/api'

const loading = ref(false)
const tableData = ref([])
const selectedOrders = ref([])
const detailVisible = ref(false)
const currentOrder = ref(null)
const reviewVisible = ref(false)
const reviewOrderId = ref(null)
const isBatchReview = ref(false)

const searchForm = reactive({
  keyword: '',
  status: null,
  date_range: []
})

const reviewForm = reactive({
  approved: true,
  review_remark: ''
})

const pagination = reactive({
  page: 1,
  page_size: 20,
  total: 0
})

const getStatusType = (status) => {
  const map = {
    0: 'info',
    10: 'warning',
    15: 'primary',
    20: 'primary',
    30: 'success',
    40: 'danger'
  }
  return map[status] || 'info'
}

const isItemMoqPassed = (item) => {
  if (!item) return false
  if (Object.prototype.hasOwnProperty.call(item, 'moq_passed')) {
    return (item.moq_passed === 1 || item.moq_passed === true || item.moq_passed === '1')
  }
  const qty = Number(item.quantity || 0)
  const moq = Number(item.moq || 0)
  return moq <= 0 || qty >= moq
}

const isOrderMoqPassed = (order) => {
  if (!order || !order.items || order.items.length === 0) return false
  if (order.moq_checked === 1) return true
  if (order.moq_checked === 2) return false
  return order.items.every(it => isItemMoqPassed(it))
}

const getOrderMoqText = (order) => {
  if (!order) return '未校验'
  if (order.moq_checked === 1) return '已通过'
  if (order.moq_checked === 2) return '未通过'
  const allPassed = isOrderMoqPassed(order)
  return allPassed ? '已通过(未同步)' : '未通过(未同步)'
}

const getOrderMoqTagType = (order) => {
  if (!order) return 'info'
  if (order.moq_checked === 1) return 'success'
  if (order.moq_checked === 2) return 'danger'
  return isOrderMoqPassed(order) ? 'success' : 'danger'
}

const loadList = async () => {
  loading.value = true
  try {
    const params = {
      ...searchForm,
      page: pagination.page,
      page_size: pagination.page_size
    }
    if (searchForm.date_range?.length) {
      params.start_date = searchForm.date_range[0]
      params.end_date = searchForm.date_range[1]
    }
    const res = await orderApi.list(params)
    tableData.value = res.data?.list || []
    pagination.total = res.data?.total || 0
    clearSelection()
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const resetSearch = () => {
  searchForm.keyword = ''
  searchForm.status = null
  searchForm.date_range = []
  pagination.page = 1
  loadList()
}

const handleSelectionChange = (val) => {
  selectedOrders.value = val
}

const tableRef = ref(null)

const clearSelection = () => {
  selectedOrders.value = []
  tableRef.value?.clearSelection()
}

const syncOrderToTable = (updatedOrder) => {
  if (!updatedOrder) return
  const idx = tableData.value.findIndex(o => o.id === updatedOrder.id)
  if (idx !== -1) {
    tableData.value[idx] = { ...tableData.value[idx], ...updatedOrder }
  }
  const selIdx = selectedOrders.value.findIndex(o => o.id === updatedOrder.id)
  if (selIdx !== -1) {
    selectedOrders.value[selIdx] = { ...selectedOrders.value[selIdx], ...updatedOrder }
  }
  if (currentOrder.value && currentOrder.value.id === updatedOrder.id) {
    currentOrder.value = { ...currentOrder.value, ...updatedOrder }
  }
}

const handleCheckMoq = async (row) => {
  try {
    const res = await orderApi.checkMoq({ order_id: row.id })
    if (res.data?.passed) {
      ElMessage.success('MOQ校验通过')
    } else {
      ElMessageBox.warning(
        res.data?.message || '部分商品未达到起订量，请调整数量后重新校验',
        'MOQ 校验未通过',
        { confirmButtonText: '我知道了', dangerouslyUseHTMLString: true }
      )
    }
    if (res.data?.order) {
      syncOrderToTable(res.data.order)
    } else {
      loadList()
    }
  } catch (e) {
    console.error(e)
  }
}

const batchCheckMoq = async () => {
  try {
    const ids = selectedOrders.value.map(o => o.id)
    const res = await orderApi.batchCheckMoq({ order_ids: ids })
    const passed = res.data?.passed || 0
    const failed = res.data?.failed || 0
    const skippedCancelled = res.data?.skipped_cancelled || 0

    if (res.data?.orders && res.data.orders.length) {
      res.data.orders.forEach(ord => syncOrderToTable(ord))
      clearSelection()
    } else {
      clearSelection()
      loadList()
    }

    const cancelledHtml = skippedCancelled > 0
      ? `<p style="color:#909399">跳过已取消: <b>${skippedCancelled}</b> 条</p>`
      : ''

    if (failed > 0 || skippedCancelled > 0) {
      ElMessageBox.warning(
        `<p>批量校验完成</p><p style="color:#67C23A">通过: <b>${passed}</b> 条</p><p style="color:#F56C6C">未通过: <b>${failed}</b> 条</p>${cancelledHtml}<p style="color:#909399;font-size:12px">请对未通过的订单调整商品数量后重新校验</p>`,
        '批量校验结果',
        { confirmButtonText: '我知道了', dangerouslyUseHTMLString: true }
      )
    } else {
      ElMessage.success(`校验完成：全部通过 ${passed} 条`)
    }
  } catch (e) {
    console.error(e)
  }
}

const retryOrderId = ref(null)
const retryOrderNo = ref('')
const retryDialogVisible = ref(false)
const retryErrorMessage = ref('')
const isRetryable = ref(false)

const handleGenerateShipping = async (row) => {
  try {
    const res = await shippingApi.generate(row.id)
    ElMessage.success('面单生成成功')
    loadList()
  } catch (e) {
    console.error(e)
    const errorData = e?.response?.data || {}
    const errMsg = errorData.message || e.message || '面单生成失败'
    const rollback = errorData.data?.rollback || false
    const retryable = errorData.data?.retryable || false
    const orderNo = errorData.data?.order_no || row.order_no
    retryOrderId.value = errorData.data?.order_id || row.id
    retryOrderNo.value = orderNo
    retryErrorMessage.value = errMsg
    isRetryable.value = retryable
    ElMessageBox({
      title: '打单失败',
      message: h('div', null, [
        h('p', { style: 'color: #F56C6C; margin-bottom: 8px' }, orderNo ? `订单 ${orderNo}：${errMsg}` : errMsg),
        rollback ? h('p', { style: 'color: #67C23A; margin-bottom: 8px' }, '✅ 数据已自动回滚，订单状态未受影响') : null,
        retryable ? h('p', { style: 'color: #E6A23C' }, '💡 您可以点击下方重试按钮再次尝试') : h('p', { style: 'color: #909399' }, '💡 请根据提示修正后再试')
      ]),
      showCancelButton: retryable,
      confirmButtonText: retryable ? '立即重试' : '我知道了',
      cancelButtonText: '取消',
      type: retryable ? 'warning' : 'error',
      dangerouslyUseHTMLString: true
    }).then(async () => {
      if (retryable && retryOrderId.value) {
        await handleGenerateShipping({ id: retryOrderId.value, order_no: retryOrderNo.value })
      }
    }).catch(() => {})
  }
}

const batchRetryOrderIds = ref([])
const batchRetryDialogVisible = ref(false)
const batchFailedOrders = ref([])

const batchGenerateShipping = async (orderIds = null) => {
  try {
    const ids = orderIds || selectedOrders.value.filter(o => o.status === 15).map(o => o.id)
    if (ids.length === 0) {
      ElMessage.warning('请选择已审核且未生成面单的订单')
      return
    }
    const res = await shippingApi.batchGenerate({ order_ids: ids })
    const success = res.data?.success || 0
    const failed = res.data?.failed || 0
    const failedOrders = res.data?.failed_orders || []
    const retryableIds = res.data?.retryable_order_ids || []

    loadList()

    if (failed === 0) {
      ElMessage.success(`成功生成 ${success} 张面单`)
    } else {
      batchFailedOrders.value = failedOrders
      batchRetryOrderIds.value = retryableIds

      const hasRollback = failedOrders.some(f => f.rollback)
      const hasRetryable = retryableIds.length > 0

      ElMessageBox({
        title: '批量打单完成',
        message: h('div', null, [
          h('p', { style: 'margin-bottom: 8px' }, [
            h('span', { style: 'color: #67C23A' }, `成功：${success} 条`),
            h('span', { style: 'margin: 0 10px; color: #909399' }, '|'),
            h('span', { style: 'color: #F56C6C' }, `失败：${failed} 条`)
          ]),
          hasRollback ? h('p', { style: 'color: #67C23A; margin-bottom: 8px' }, '✅ 失败订单数据已自动回滚，订单状态未受影响') : null,
          failedOrders.length > 0 ? h('div', { style: 'margin-top: 12px' }, [
            h('p', { style: 'font-weight: 600; margin-bottom: 8px' }, '失败详情：'),
            h('div', { style: 'max-height: 150px; overflow-y: auto; background: #f5f7fa; padding: 8px; border-radius: 4px' },
              failedOrders.map((f, idx) => h('div', { key: idx, style: 'font-size: 12px; padding: 4px 0; border-bottom: 1px dashed #e4e7ed' }, [
                h('span', { style: 'font-weight: 600' }, f.order_no || `订单${f.order_id}`),
                h('span', { style: 'color: #F56C6C; margin-left: 8px' }, f.message)
              ]))
            )
          ]) : null,
          hasRetryable ? h('p', { style: 'color: #E6A23C; margin-top: 12px' }, `💡 有 ${retryableIds.length} 条订单可重试`) : null
        ]),
        showCancelButton: hasRetryable,
        confirmButtonText: hasRetryable ? '重试失败订单' : '我知道了',
        cancelButtonText: '关闭',
        type: failed > 0 ? 'warning' : 'success',
        dangerouslyUseHTMLString: true
      }).then(async () => {
        if (hasRetryable && batchRetryOrderIds.value.length > 0) {
          await batchGenerateShipping(batchRetryOrderIds.value)
        }
      }).catch(() => {})
    }
  } catch (e) {
    console.error(e)
    const errMsg = e?.message || '批量打单失败'
    ElMessage.error(errMsg)
  }
}

const viewDetail = async (row) => {
  try {
    const res = await orderApi.detail(row.id)
    currentOrder.value = res.data
    detailVisible.value = true
  } catch (e) {
    console.error(e)
  }
}

const checkMoqFromDetail = async () => {
  if (!currentOrder.value) return
  try {
    const res = await orderApi.checkMoq({ order_id: currentOrder.value.id })
    if (res.data?.passed) {
      ElMessage.success('MOQ校验通过')
    } else {
      ElMessageBox.warning(
        res.data?.message || '部分商品未达到起订量，请调整数量后重新校验',
        'MOQ 校验未通过',
        { confirmButtonText: '我知道了', dangerouslyUseHTMLString: true }
      )
    }
    if (res.data?.order) {
      syncOrderToTable(res.data.order)
    } else {
      viewDetail({ id: currentOrder.value.id })
    }
  } catch (e) {
    console.error(e)
  }
}

const handleDelete = async (row) => {
  try {
    await ElMessageBox.confirm(`确定删除订单「${row.order_no}」吗？`, '提示', { type: 'warning' })
    await orderApi.delete(row.id)
    ElMessage.success('删除成功')
    loadList()
  } catch (e) {
    if (e !== 'cancel') console.error(e)
  }
}

const openReview = (row, approved) => {
  reviewOrderId.value = row.id
  isBatchReview.value = false
  reviewForm.approved = approved
  reviewForm.review_remark = ''
  reviewVisible.value = true
}

const openBatchReview = (approved) => {
  const validOrders = selectedOrders.value.filter(o => o.status === 10)
  if (validOrders.length === 0) {
    ElMessage.warning('请选择MOQ已通过且待审核的订单')
    return
  }
  reviewOrderId.value = null
  isBatchReview.value = true
  reviewForm.approved = approved
  reviewForm.review_remark = ''
  reviewVisible.value = true
}

const submitReview = async () => {
  try {
    if (isBatchReview.value) {
      const ids = selectedOrders.value.filter(o => o.status === 10).map(o => o.id)
      const res = await orderApi.batchReview({
        order_ids: ids,
        approved: reviewForm.approved,
        review_remark: reviewForm.review_remark
      })
      if (res.data?.orders && res.data.orders.length) {
        res.data.orders.forEach(ord => syncOrderToTable(ord))
        clearSelection()
      } else {
        loadList()
      }
      ElMessage.success(`批量${reviewForm.approved ? '审核通过' : '审核驳回'}完成：成功 ${res.data?.success || 0} 条，失败 ${res.data?.failed || 0} 条`)
    } else {
      const res = await orderApi.review(reviewOrderId.value, {
        approved: reviewForm.approved,
        review_remark: reviewForm.review_remark
      })
      if (res.data) {
        syncOrderToTable(res.data)
      } else {
        loadList()
      }
      ElMessage.success(reviewForm.approved ? '审核通过' : '审核驳回')
    }
    reviewVisible.value = false
  } catch (e) {
    console.error(e)
  }
}

onMounted(loadList)
</script>
