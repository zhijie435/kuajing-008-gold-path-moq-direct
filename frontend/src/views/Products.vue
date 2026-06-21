<template>
  <div class="page-container">
    <div class="page-header">
      <h2 class="page-title">产品管理</h2>
    </div>

    <div class="card">
      <div class="toolbar">
        <div class="search-form">
          <el-input v-model="searchForm.keyword" placeholder="SKU/产品名称" clearable style="width: 240px" />
          <el-button type="primary" @click="loadList">
            <el-icon><Search /></el-icon> 搜索
          </el-button>
          <el-button @click="resetSearch">
            <el-icon><Refresh /></el-icon> 重置
          </el-button>
        </div>
        <el-button type="primary" @click="openCreate">
          <el-icon><Plus /></el-icon> 新增产品
        </el-button>
      </div>

      <el-table :data="tableData" v-loading="loading" stripe>
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="sku" label="SKU" width="140" />
        <el-table-column prop="name" label="产品名称" min-width="180" />
        <el-table-column prop="category" label="分类" width="100" />
        <el-table-column prop="moq" label="起订量(MOQ)" width="110">
          <template #default="{ row }">
            <el-tag type="warning">{{ row.moq }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="unit" label="单位" width="80" />
        <el-table-column prop="price" label="单价(¥)" width="100" />
        <el-table-column prop="stock" label="库存" width="80" />
        <el-table-column prop="weight" label="重量(g)" width="100" />
        <el-table-column prop="created_at" label="创建时间" width="170" />
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link @click="openEdit(row)">编辑</el-button>
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

    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="600px"
      @close="resetForm"
    >
      <el-form ref="formRef" :model="formData" :rules="formRules" label-width="100px">
        <el-form-item label="SKU编码" prop="sku">
          <el-input v-model="formData.sku" placeholder="请输入SKU编码" />
        </el-form-item>
        <el-form-item label="产品名称" prop="name">
          <el-input v-model="formData.name" placeholder="请输入产品名称" />
        </el-form-item>
        <el-form-item label="分类" prop="category">
          <el-input v-model="formData.category" placeholder="请输入分类" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="起订量" prop="moq">
              <el-input-number v-model="formData.moq" :min="1" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="单位" prop="unit">
              <el-input v-model="formData.unit" placeholder="件/个/箱" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="单价(¥)" prop="price">
              <el-input-number v-model="formData.price" :min="0" :precision="2" style="width: 100%" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="库存" prop="stock">
              <el-input-number v-model="formData.stock" :min="0" style="width: 100%" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="重量(g)" prop="weight">
          <el-input-number v-model="formData.weight" :min="0" :precision="2" style="width: 50%" />
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="formData.remark" type="textarea" :rows="3" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="submitForm">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { productApi } from '@/api'

const loading = ref(false)
const tableData = ref([])
const dialogVisible = ref(false)
const dialogTitle = ref('新增产品')
const editingId = ref(null)
const formRef = ref(null)

const searchForm = reactive({
  keyword: ''
})

const pagination = reactive({
  page: 1,
  page_size: 20,
  total: 0
})

const defaultForm = () => ({
  sku: '',
  name: '',
  category: '',
  moq: 1,
  unit: '件',
  price: 0,
  stock: 0,
  weight: 0,
  remark: ''
})

const formData = reactive(defaultForm())

const formRules = {
  sku: [{ required: true, message: '请输入SKU编码', trigger: 'blur' }],
  name: [{ required: true, message: '请输入产品名称', trigger: 'blur' }],
  moq: [{ required: true, message: '请输入起订量', trigger: 'blur' }],
  price: [{ required: true, message: '请输入单价', trigger: 'blur' }]
}

const loadList = async () => {
  loading.value = true
  try {
    const res = await productApi.list({
      ...searchForm,
      page: pagination.page,
      page_size: pagination.page_size
    })
    tableData.value = res.data?.list || []
    pagination.total = res.data?.total || 0
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const resetSearch = () => {
  searchForm.keyword = ''
  pagination.page = 1
  loadList()
}

const openCreate = () => {
  dialogTitle.value = '新增产品'
  editingId.value = null
  Object.assign(formData, defaultForm())
  dialogVisible.value = true
}

const openEdit = (row) => {
  dialogTitle.value = '编辑产品'
  editingId.value = row.id
  Object.assign(formData, row)
  dialogVisible.value = true
}

const resetForm = () => {
  Object.assign(formData, defaultForm())
  editingId.value = null
  formRef.value?.resetFields()
}

const submitForm = async () => {
  if (!formRef.value) return
  await formRef.value.validate()
  try {
    if (editingId.value) {
      await productApi.update(editingId.value, formData)
      ElMessage.success('更新成功')
    } else {
      await productApi.create(formData)
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false
    loadList()
  } catch (e) {
    console.error(e)
  }
}

const handleDelete = async (row) => {
  try {
    await ElMessageBox.confirm(`确定删除产品「${row.name}」吗？`, '提示', {
      type: 'warning'
    })
    await productApi.delete(row.id)
    ElMessage.success('删除成功')
    loadList()
  } catch (e) {
    if (e !== 'cancel') console.error(e)
  }
}

onMounted(loadList)
</script>
