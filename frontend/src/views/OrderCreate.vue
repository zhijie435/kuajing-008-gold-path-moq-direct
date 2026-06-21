<template>
  <div class="page-container">
    <div class="page-header flex-between">
      <h2 class="page-title">新建订单</h2>
      <el-button @click="$router.back()">
        <el-icon><ArrowLeft /></el-icon> 返回
      </el-button>
    </div>

    <div class="card">
      <el-steps :active="step" finish-status="success" class="mb-16">
        <el-step title="填写收件信息" />
        <el-step title="添加商品" />
        <el-step title="MOQ校验确认" />
      </el-steps>

      <div v-if="step === 0">
        <el-form ref="receiverFormRef" :model="formData" :rules="receiverRules" label-width="100px" style="max-width: 800px">
          <el-form-item label="收件人" prop="receiver_name">
            <el-input v-model="formData.receiver_name" placeholder="请输入收件人姓名" style="width: 300px" />
          </el-form-item>
          <el-form-item label="联系电话" prop="receiver_phone">
            <el-input v-model="formData.receiver_phone" placeholder="请输入联系电话" style="width: 300px" />
          </el-form-item>
          <el-form-item label="收件地址" prop="receiver_address">
            <el-input v-model="formData.receiver_address" type="textarea" :rows="3" placeholder="省/市/区/详细地址" />
          </el-form-item>
          <el-form-item label="备注">
            <el-input v-model="formData.remark" type="textarea" :rows="2" />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="goToStep(1)">下一步</el-button>
          </el-form-item>
        </el-form>
      </div>

      <div v-if="step === 1">
        <div class="toolbar">
          <div>
            <el-button type="primary" @click="openProductSelect">
              <el-icon><Plus /></el-icon> 添加商品
            </el-button>
          </div>
          <div>
            已选商品: <b>{{ totalQuantity }}</b> 件
          </div>
        </div>

        <el-table :data="formData.items" border>
          <el-table-column prop="sku" label="SKU" width="140" />
          <el-table-column prop="name" label="产品名称" min-width="180" />
          <el-table-column prop="moq" label="MOQ起订量" width="110">
            <template #default="{ row }">
              <el-tag type="warning">{{ row.moq }} {{ row.unit }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="下单数量" width="180">
            <template #default="{ row, $index }">
              <el-input-number v-model="row.quantity" :min="1" @change="validateMoqItem($index)" />
              <span v-if="row.moq > row.quantity" style="color: #F56C6C; margin-left: 8px; font-size: 12px">
                低于MOQ
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="unit" label="单位" width="80" />
          <el-table-column label="操作" width="100">
            <template #default="{ $index }">
              <el-button type="danger" link @click="removeItem($index)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <div class="flex-gap mt-16">
          <el-button @click="goToStep(0)">上一步</el-button>
          <el-button type="primary" :disabled="formData.items.length === 0" @click="doMoqCheck">
            <el-icon><CircleCheck /></el-icon> MOQ校验并确认
          </el-button>
        </div>
      </div>

      <div v-if="step === 2">
        <el-alert
          :title="moqResult.passed ? '所有商品均满足起订量要求，可以提交订单' : '存在商品未达到起订量，无法提交订单'"
          :type="moqResult.passed ? 'success' : 'error'"
          :closable="false"
          show-icon
          class="mb-16"
        />
        <el-result :icon="moqResult.passed ? 'success' : 'warning'" :title="moqResult.passed ? 'MOQ校验通过' : 'MOQ校验未通过'">
          <template #sub-title>
            <span style="color:#F56C6C">{{ moqResult.message }}</span>
          </template>
          <template #extra>
            <div class="card" style="max-width: 100%; margin: 0 auto">
              <el-table :data="moqResult.items" border>
                <el-table-column prop="sku" label="SKU" width="140" />
                <el-table-column prop="name" label="产品名称" />
                <el-table-column prop="moq" label="MOQ起订量" width="110" />
                <el-table-column prop="quantity" label="下单数量" width="110">
                  <template #default="{ row }">
                    <span :style="{ color: row.quantity < row.moq ? '#F56C6C' : '', fontWeight: row.quantity < row.moq ? 600 : 400 }">
                      {{ row.quantity }}
                      <span v-if="row.quantity < row.moq" style="font-size:12px"> (差{{ row.moq - row.quantity }})</span>
                    </span>
                  </template>
                </el-table-column>
                <el-table-column label="校验结果" width="120">
                  <template #default="{ row }">
                    <el-tag v-if="row.passed" type="success">通过</el-tag>
                    <el-tag v-else type="danger">未通过</el-tag>
                  </template>
                </el-table-column>
              </el-table>
            </div>
            <div class="flex-gap mt-16" style="justify-content: center">
              <el-button @click="goToStep(1)">返回修改数量</el-button>
              <el-tooltip
                v-if="!moqResult.passed"
                content="请先调整商品数量达到MOQ要求后再提交"
                placement="top"
              >
                <el-button type="primary" disabled>
                  <el-icon><Check /></el-icon> 提交订单
                </el-button>
              </el-tooltip>
              <el-button v-if="moqResult.passed" type="primary" @click="submitOrder">
                <el-icon><Check /></el-icon> 提交订单
              </el-button>
            </div>
          </template>
        </el-result>
      </div>
    </div>

    <el-dialog v-model="productSelectVisible" title="选择商品" width="900px">
      <div class="toolbar">
        <el-input v-model="productKeyword" placeholder="搜索SKU/产品名称" clearable style="width: 260px" @keyup.enter="loadProducts" />
        <el-button type="primary" @click="loadProducts">搜索</el-button>
      </div>
      <el-table
        :data="productList"
        v-loading="productLoading"
        @selection-change="handleProductSelection"
        height="400"
      >
        <el-table-column type="selection" width="50" />
        <el-table-column prop="sku" label="SKU" width="140" />
        <el-table-column prop="name" label="产品名称" />
        <el-table-column prop="category" label="分类" width="100" />
        <el-table-column prop="moq" label="MOQ" width="100" />
        <el-table-column prop="unit" label="单位" width="80" />
        <el-table-column prop="stock" label="库存" width="80" />
      </el-table>
      <div class="mt-16 flex-between">
        <el-pagination
          v-model:current-page="productPage"
          v-model:page-size="productPageSize"
          :page-sizes="[10, 20, 50]"
          :total="productTotal"
          layout="total, prev, pager, next"
          @current-change="loadProducts"
          @size-change="loadProducts"
        />
        <div>
          <el-button @click="productSelectVisible = false">取消</el-button>
          <el-button type="primary" @click="confirmProductSelect">确认添加</el-button>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { orderApi, productApi } from '@/api'

const router = useRouter()

const step = ref(0)
const receiverFormRef = ref(null)

const defaultForm = () => ({
  receiver_name: '',
  receiver_phone: '',
  receiver_address: '',
  remark: '',
  items: []
})

const formData = reactive(defaultForm())

const receiverRules = {
  receiver_name: [{ required: true, message: '请输入收件人', trigger: 'blur' }],
  receiver_phone: [{ required: true, message: '请输入联系电话', trigger: 'blur' }],
  receiver_address: [{ required: true, message: '请输入收件地址', trigger: 'blur' }]
}

const totalQuantity = computed(() => formData.items.reduce((sum, item) => sum + item.quantity, 0))

const moqResult = reactive({
  passed: false,
  message: '',
  items: []
})

const productSelectVisible = ref(false)
const productKeyword = ref('')
const productLoading = ref(false)
const productList = ref([])
const productSelected = ref([])
const productPage = ref(1)
const productPageSize = ref(10)
const productTotal = ref(0)

const goToStep = async (idx) => {
  if (idx === 1) {
    await receiverFormRef.value?.validate()
  }
  step.value = idx
}

const openProductSelect = () => {
  productKeyword.value = ''
  productPage.value = 1
  loadProducts()
  productSelectVisible.value = true
}

const loadProducts = async () => {
  productLoading.value = true
  try {
    const res = await productApi.list({
      keyword: productKeyword.value,
      page: productPage.value,
      page_size: productPageSize.value
    })
    productList.value = res.data?.list || []
    productTotal.value = res.data?.total || 0
  } catch (e) {
    console.error(e)
  } finally {
    productLoading.value = false
  }
}

const handleProductSelection = (val) => {
  productSelected.value = val
}

const confirmProductSelect = () => {
  const existingSkus = formData.items.map(i => i.sku)
  productSelected.value.forEach(p => {
    if (!existingSkus.includes(p.sku)) {
      formData.items.push({
        product_id: p.id,
        sku: p.sku,
        name: p.name,
        moq: p.moq,
        unit: p.unit,
        quantity: p.moq,
        price: p.price
      })
    }
  })
  productSelectVisible.value = false
  ElMessage.success(`已添加 ${productSelected.value.length} 个商品`)
}

const removeItem = (index) => {
  formData.items.splice(index, 1)
}

const validateMoqItem = (index) => {
}

const doMoqCheck = async () => {
  try {
    const res = await orderApi.checkMoq({ items: formData.items })
    moqResult.passed = res.data?.passed || false
    moqResult.message = res.data?.message || ''
    moqResult.items = res.data?.items || []
    step.value = 2
  } catch (e) {
    console.error(e)
  }
}

const submitOrder = async () => {
  try {
    const res = await orderApi.create(formData)
    ElMessage.success('订单创建成功')
    router.push('/orders')
  } catch (e) {
    console.error(e)
  }
}

onMounted(() => {})
</script>
