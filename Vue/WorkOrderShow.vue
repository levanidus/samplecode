<template>
  <div class="relative w-full">
    <!-- modals -->
    <b-modal
      id="material-consumption"
      size="xl"
      :title="formСonsumptionEditMode ? 'Расход материала (редактирование)' : 'Расход материала'"
      centered
    >
      <div class="mb-3">
        <p>Название работы:</p>
        <p class="font-bold">
          {{ currentWork.description }}
        </p>
      </div>

      <div class="grid gap-3">
        <label>
          <p>Тип</p>
          <b-select
            v-model="formСonsumptionType"
            :options="formСonsumptionTypeOptions"
          />
        </label>

        <WarehouseItemConsumption
          ref="warehouseItemConsumption"
          :сonsumption-type="formСonsumptionType"
          :work-order-id="parseInt(this.$route.params.id)"
          @validation-change="onConsumptionFormValidationChanged"
        />
      </div>

      <template #modal-footer="{cancel}">
        <div>
          <b-button
            :disabled="isLoadingСonsumption || !isConsumptionFormValid"
            variant="primary"
            class="float-right ml-1"
            @click="onSubmitСonsumption()"
          >
            <b-spinner v-if="isLoadingСonsumption" small />
            {{ formСonsumptionEditMode ? 'Изменить' : 'Добавить' }}
          </b-button>
          <b-button
            class="float-right"
            @click="cancel()"
          >
            Отмена
          </b-button>
        </div>
      </template>
    </b-modal>

    <b-modal
      id="modal-work"
      size="lg"
      :title="isEditModeForWork === true ? 'Редактирование работы' : 'Добавление работы'"
      centered
      @shown="$refs.workInput.focus()"
    >
      <b-form-group label="Наименование работы:">
        <b-form-input ref="workInput" v-model="workForm.description" />
      </b-form-group>
      <b-form-group label="Фото/Видео:">
        <div class="relative grid grid-cols-5 gap-3">
          <button
            class="w-full h-24 border border-dashed rounded"
            @click="$refs['filePicker'].click()"
          >
            <b-icon icon="plus-circle" />
          </button>
          <div
            v-for="(m, idx) in workForm.media"
            :key="idx"
            class="relative w-full h-24 border border-dashed rounded"
          >
            <a :href="m" target="_blank">
              <img
                v-if="m.includes('mp4') || m.includes('mkv')"
                :src="require('../../assets/play-button.png')"
                :alt="idx"
                class="object-cover w-full h-full rounded"
              >
              <img
                v-else
                :src="m"
                :alt="idx"
                class="object-cover w-full h-full rounded"
              >
            </a>
            <button class="absolute top-1 right-2" @click="removeImage(idx)">
              <b-icon icon="x" />
            </button>
            <b-icon icon="file-earmark-image" class="absolute bottom-2 right-2" />
          </div>
        </div>
      </b-form-group>
      <b-form-group>
        <b-button variant="primary" size="sm" @click="toggleShowMediaGallery">Открыть галерею</b-button>
      </b-form-group>
      <b-form-group v-if="showMediaGallery">
        <b-carousel
          id="carousel-1"
          v-model="slide"
          :interval="0"
          controls
          indicators
          background="#ababab"
          style="text-shadow: 1px 1px 2px #333;"
          @sliding-start="onSlideStart"
          @sliding-end="onSlideEnd"
        >
          <b-carousel-slide
            v-for="(m, idx) in formMediaImages"
            :key="idx"
            :img-src="m"
          >
          </b-carousel-slide>
        </b-carousel>
      </b-form-group>
      <template #modal-footer="{cancel}">
        <div>
          <b-button
            :disabled="isLoading || !workFormValid"
            variant="primary"
            class="float-right ml-1"
            @click="onSubmitWork"
          >
            <b-spinner v-if="isLoading" small />
            {{ isEditModeForWork === true ? 'Сохранить' : 'Добавить' }}
          </b-button>
          <b-button
            class="float-right"
            @click="cancel()"
          >
            Отмена
          </b-button>
        </div>
      </template>
    </b-modal>


    <ModalFutureWork
      :is-edit="isEditModeForFutureWork"
      :future-work-form="futureWorkForm"
      @updateDescription="futureWorkForm.description = $event"
      @callback="getDataFutureWorks()"
    />

    <b-modal
      id="material-future-consumption"
      size="lg"
      :title="formFutureConsumptionEditMode ? 'План – Расход материала (редактирование)' : 'План – Расход материала'"
      centered
      @hidden="clearFutureConsumptionForm"
    >
      <div class="mb-3">
        <p>Название работы:</p>
        <p class="font-bold">
          {{ currentFutureWork.description }}
        </p>
      </div>

      <div class="grid gap-3">
        <div>
          <p>Позиция номенклатуры:</p>
          <nomenclature-select v-model="formFutureConsumptionNomenclatureSelected" />
        </div>
      </div>

      <div class="grid gap-3">
        <label>
          <p>
            Объем<span v-if="formFutureConsumptionNomenclatureSelected">, {{ formFutureConsumptionNomenclatureSelected.unit_name | unit_short_name }}</span>:
          </p>
          <b-input v-model="formFutureConsumption.volume" type="number" min="0" />
        </label>
      </div>

      <template #modal-footer="{cancel}">
        <div>
          <b-button
            :disabled="isLoadingFutureConsumption || !futureConsumptionFormValid"
            variant="primary"
            class="float-right ml-1"
            @click="onSubmitFutureConsumption()"
          >
            <b-spinner v-if="isLoadingFutureConsumption" small />
            {{ formFutureConsumptionEditMode ? 'Изменить' : 'Добавить' }}
          </b-button>
          <b-button
            class="float-right"
            @click="cancel()"
          >
            Отмена
          </b-button>
        </div>
      </template>
    </b-modal>
    <!-- /modals -->

    <!-- body -->
    <div>
      <div class="relative flex w-full max-h-screen overflow-y-auto abz-card-body">
        <!-- left -->
        <div class="sticky top-0 w-64 min-h-screen p-4 border-r">
          <h1 class="mb-4">
            Просмотр заказа-наряда
          </h1>

          <div class="space-y-3">
            <!-- btns -->
            <div class="flex justify-between">
              <b-button
                variant="outline-secondary"
                :to="{ name: 'WorkOrdersList'}"
              >
                К списку
              </b-button>

              <ActionButtons
                class="text-2xl"
                :items="['edit', 'remove']"
                @edit="$router.push({ name: 'WorkOrderEdit', params: { id: $route.params.id }})"
                @remove="toggleRemove"
              />
            </div>
            <!-- /btns -->

            <div>
              <p class="text-gray-400 font-italic">
                ID# {{ work_order.id }}
              </p>
            </div>

            <div>
              <p class="text-gray-400 text-sm font-italic">
                Даты
              </p>
              <p>
                {{ !work_order.start_date && !work_order.end_date ? '–' : '' }}
                {{ work_order.start_date ? formatDate(work_order.start_date) : '' }}
                {{ !work_order.start_date && work_order.end_date ? '*' : '' }}
                {{ work_order.end_date ? ' – ' : '' }}
                {{ formatDate(work_order.end_date) }}
              </p>
              <router-link
                v-if="work_order.id && (work_order.status === 'progress' || work_order.status === 'planned')"
                :to="{ name: 'ProductionSchedule', query: { workOrderId: work_order.id } }"
              >
                Смотреть в графике
              </router-link>
            </div>

            <div>
              <p class="text-gray-400 text-sm font-italic">
                Статус
              </p>
              <p>{{ work_order.status | work_order_status_info }}</p>
              <b-badge v-if="!isNomenclatureEnough" variant="danger">Не хватает материалов</b-badge>
            </div>

            <div class="flex items-end mt-4">
              <p class="text-lg font-italic font-bold mr-4">
                Сделка
              </p>
              <p v-if="work_order.deal" class="text-gray-300 text-sm font-italic mb-0.5">
                <router-link :to="{ name: 'Deal', params: { id: work_order.deal.id } }">
                  ID #{{ work_order.deal ? work_order.deal.id : '–' }}
                </router-link>
              </p>
              <p v-else>
                Не указан
              </p>
            </div>
            <template v-if="showUserContact">
              <div v-if="work_order.deal">
                <p class="text-gray-400 text-sm font-italic">
                  Клиент
                </p>
                <p v-if="work_order.deal.car">
                  <router-link :to="{ name: 'Contact', params: {id: work_order.deal.car.owner.id}}">
                    {{ work_order.deal.car.owner.id }}
                  </router-link>
                  {{ work_order.deal.car.owner.last_name }}
                  {{ work_order.deal.car.owner.first_name }}
                  {{ work_order.deal.car.owner.middle_name }}
                </p>
                <div v-if="work_order.deal.car" class="flex">
                  <p>{{ work_order.deal.car.owner.phone }}</p>
                  <button
                    v-if="false"
                    class="ml-2 w-6 h-6 bg-success text-white rounded "
                  >
                    <b-icon icon="telephone-fill" />
                  </button>
                </div>
              </div>
            </template>
            <div v-if="work_order.deal">
              <p class="text-gray-400 text-sm font-italic">
                Автомобиль
              </p>
              <p v-if="work_order.deal.car">
                <router-link :to="{ name: 'Car', params: {id: work_order.deal.car.id}}">
                  {{ work_order.deal.car.id }}
                </router-link>
                {{ work_order.deal.car.brand }} {{ work_order.deal.car.model }} {{ work_order.deal.car.license_plate }}
              </p>
              <p v-if="work_order.deal.car">
                {{ work_order.deal.car.year_of_manufacture ? work_order.deal.car.year_of_manufacture + ' год' : '' }}
                {{ work_order.deal.car.year_of_manufacture && work_order.deal.car.mileage > 0 ? ', ' : '' }}
                {{ work_order.deal.car.mileage > 0 ? Intl.NumberFormat('ru-RU').format(work_order.deal.car.mileage) + ' км' : '' }}
              </p>
              <p v-else>
                –
              </p>
            </div>
            <div v-if="work_order.deal">
              <p class="text-gray-400 text-sm font-italic">
                Менеджер
              </p>
              <p v-if="work_order.deal.responsible_id">
                {{ work_order.deal.responsible_id | responsible_info }}
              </p>
              <p v-else>
                –
              </p>
            </div>

            <div class="mt-4">
              <p class="text-lg font-italic font-bold mr-4">
                Наряд
              </p>
            </div>
            <div>
              <p class="text-gray-400 text-sm font-italic">
                Ответственный
              </p>
              <p>{{ work_order.responsible_id | responsible_info }}</p>
            </div>
          </div>
        </div>
        <!-- /left -->
        <!-- right -->
        <div class="relative flex-1 w-full p-4 min-h-screen space-y-4">
          <b-tabs v-model="tabIndex" pills>
            <b-tab class="pt-2">
              <template v-slot:title>
                План <b-badge v-if="!isNomenclatureEnough" variant="danger">!</b-badge>
              </template>
              <div>
                <p class="text-lg font-italic font-bold mr-4 mb-1">
                  Запланированные работы
                </p>
                <div class="space-y-2 mb-3">
                  <div
                    v-for="(work, idx) in future_works"
                    :key="idx"
                  >
                    <!-- main-line -->
                    <div class="flex justify-between mb-1">
                      <div class="flex">
                        <p class="flex-1">
                          {{ work.description }}
                        </p>
                      </div>
                      <div class="flex">
                        <button
                          class="ml-2 w-6 h-6 bg-warning text-white rounded"
                          @click="toggleFutureMaterialConsumption(work)"
                        >
                          <b-icon
                            title="Добавить затраты"
                            icon="droplet-half"
                            aria-hidden="true"
                          />
                        </button>
                        <button
                          class="ml-2 w-6 h-6 bg-success text-white rounded"
                          @click="toggleEditFutureWork(work)"
                        >
                          <b-icon
                            title="Редактировать работу"
                            icon="pencil"
                            aria-hidden="true"
                          />
                        </button>
                        <button
                          class="ml-2 w-6 h-6 bg-success text-white rounded"
                          @click="toggleRemoveFutureWork(work)"
                        >
                          <b-icon
                            title="Удалить работу"
                            icon="x-circle"
                            aria-hidden="true"
                          />
                        </button>
                      </div>
                    </div>
                    <!-- /main-line -->
                    <!-- sub-lines -->
                    <div
                      v-for="consumption in work.consumptions"
                      :key="consumption.id"
                    >
                      <div class="pl-12 flex justify-between mb-1">
                        <div class="flex w-full justify-between">
                          <div class="flex-1 border-b">
                            <p>
                              {{ consumption.nomenclature_name }}
                              <b-badge
                                v-if="nomenclatureNotEnoughIds.includes(consumption.nomenclature_id)"
                                variant="danger"
                                class="ml-1"
                              >
                                !
                              </b-badge>
                            </p>
                            <p v-if="consumption.description && consumption.description.trim().length > 0" class="text-sm">
                              <span class="text-gray-400 font-italic">
                                Описание:
                              </span>
                              {{ consumption.description }}
                            </p>
                          </div>
                          <div class="text-right border-b">
                            {{ consumption.volume }} {{ consumption.nomenclature_unit | unit_short_name }}
                          </div>
                        </div>
                        <div class="flex border-b">
                          <button
                            class="ml-2 w-6 h-6  rounded"
                          >
                            <b-icon icon="pencil" aria-hidden="true" @click="toggleEditFutureConsumption(work, consumption)" />
                          </button>
                          <button
                            class="ml-2 w-6 h-6 e rounded"
                          >
                            <b-icon icon="x-circle" aria-hidden="true" @click="toggleRemoveFutureConsumption(consumption)" />
                          </button>
                        </div>
                      </div>
                    </div>
                    <!-- /sub-lines -->
                  </div>
                </div>
                <b-button
                  variant="outline-secondary"
                  @click="toggleAddFutureWork"
                >
                  Добавить работу
                </b-button>
              </div>
            </b-tab>
            <b-tab title="Факт" class="pt-2">
              <!-- works -->
              <div>
                <div>
                  <b-button
                    variant="outline-secondary"
                    @click="toggleAddWork"
                  >
                    Добавить работу
                  </b-button>
                </div>
                <br>
                <div class="space-y-2 mb-3">
                  <div
                    v-for="(work, idx) in works"
                    :key="idx"
                  >
                    <!-- main-line -->
                    <div class="flex justify-between mb-1">
                      <div class="flex">
                        <b-form-checkbox
                          v-model="rowsChecked"
                          :value="work.id"
                        />
                        <p class="flex-1">
                          <span class="font-italic text-gray-400 mr-2">
                            {{ work.created_at }}
                          </span>
                          {{ work.description }}
                          <b-icon-card-image
                            v-if="work.media.length">
                          </b-icon-card-image>
                        </p>
                      </div>
                      <div class="flex">
                        <button
                          class="ml-2 w-6 h-6 bg-danger text-white rounded"
                          @click="showMaterialConsumption(work)"
                        >
                          <b-icon
                            title="Добавить затраты"
                            icon="droplet-half"
                            aria-hidden="true"
                          />
                        </button>
                        <button
                          class="ml-2 w-6 h-6 bg-success text-white rounded"
                          @click="toggleEditWork(work)"
                        >
                          <b-icon
                            title="Редактировать работу"
                            icon="pencil"
                            aria-hidden="true"
                          />
                        </button>
                        <button
                          class="ml-2 w-6 h-6 bg-success text-white rounded"
                          @click="toggleRemoveWork(work)"
                        >
                          <b-icon
                            title="Удалить работу"
                            icon="x-circle"
                            aria-hidden="true"
                          />
                        </button>
                      </div>
                    </div>
                    <!-- /main-line -->
                    <!-- sub-lines -->
                    <div
                      v-for="consumption in work.consumptions"
                      :key="consumption.id"
                    >
                      <div class="pl-12 flex justify-between mb-1">
                        <div class="flex w-full justify-between">
                          <div class="flex-1 border-b">
                            <p :class="{'soft-delete': consumption.deleted_at != null}">
                              <span class="font-italic text-gray-400" >
                                {{ consumption.type_name }}
                                {{ consumption.responsible_name }}
                                <router-link :to="{name: 'WarehouseShowCard', params: {id: consumption.warehouse_item.id}}">
                                  ID#{{ consumption.warehouse_item.id }}
                                </router-link>
                              </span>
                              {{ consumption.warehouse_item.nomenclature_name }}
                              <b-icon-card-image
                                v-if="consumption.media.length">
                              </b-icon-card-image>
                            </p>
                            <p
                              v-if="consumption.description && consumption.description.trim().length > 0"
                              class="text-sm"
                              :class="{'soft-delete': consumption.deleted_at != null}"
                            >
                              <span class="text-gray-400 font-italic">
                                Описание:
                              </span>
                              {{ consumption.description }}
                            </p>
                          </div>
                          <div
                            class="text-right border-b"
                            :class="{'soft-delete': consumption.deleted_at != null}">
                            {{ consumption.volume }} {{ consumption.warehouse_item.nomenclature_unit | unit_short_name }}
                            <p
                              v-if="consumption.cutoffs && consumption.cutoffs.length > 0"
                              class="border-t"
                            >
                              <span class="text-gray-400 text-sm font-italic">
                                отрезы
                              </span>
                              <span
                                v-for="(c, i) in consumption.cutoffs"
                                :key="i"
                              >
                                {{ c.width }} X {{ c.height }}<br>
                              </span>
                            </p>
                            <p
                              v-if="consumption.volume_m2 && consumption.volume_m2.length > 0"
                              class="border-t"
                            >
                              <span class="text-gray-400 text-sm font-italic">
                                расход (м2)
                              </span>
                              <span
                                v-for="(c, i) in consumption.volume_m2"
                                :key="i"
                              >
                                {{ c.width }} X {{ c.height }}<br>
                              </span>
                            </p>
                            <p
                              v-if="consumption.remaining_square && consumption.remaining_square.length > 0"
                              class="border-t"
                            >
                              <span class="text-gray-400 text-sm font-italic">
                                остаток (м2)
                              </span>
                              <span
                                v-for="(c, i) in consumption.remaining_square"
                                :key="i"
                              >
                                {{ c.width }} X {{ c.height }}<br>
                              </span>
                            </p>
                          </div>
                        </div>
                        <div
                          class="flex border-b"
                          :class="{'soft-delete': consumption.deleted_at != null}"
                        >
                          <button
                            v-if="consumption.deleted_at == null"
                            class="ml-2 w-6 h-6  rounded"
                          >
                            <b-icon icon="pencil" aria-hidden="true" @click="toggleEditConsumption(work, consumption)" />
                          </button>
                          <button
                            v-if="consumption.deleted_at == null"
                            class="ml-2 w-6 h-6 e rounded"
                          >
                            <b-icon icon="x-circle" aria-hidden="true" @click="toggleRemoveConsumption(consumption)" />
                          </button>
                        </div>
                      </div>
                    </div>
                    <!-- /sub-lines -->
                  </div>
                </div>
              </div>
              <!-- /works -->
            </b-tab>
          </b-tabs>
        </div>
        <!-- /right -->
      </div>
    </div>
    <input ref="filePicker" type="file" class="fixed h-1 w-1 top-0 left-0 opacity-0" @change="uploadFile">
    <!-- /body -->
  </div>
</template>


<script>
import { MATERIAL_CONSUMPTION_TYPE, WORK_ORDER_STATUS } from '@/constants.js'
// ui
import WarehouseItemConsumption from '@/views/warehouse/WarehouseItemConsumption.vue'
import ActionButtons from '@/components/_base/ActionButtons.vue'
import NomenclatureSelect from '@/components/NomenclatureSelect'
import dayjs from 'dayjs'
import EndOfResources from '@/mixins/end-of-resources'
import ModalFutureWork from '@/components/WorkOrder/ModalFutureWork.vue'

export default {
  components: {
    WarehouseItemConsumption,
    ActionButtons,
    NomenclatureSelect,
    ModalFutureWork,
  },
  mixins: [EndOfResources],
  data: () => ({
    isLoading: false,
    consumption_action: 'work',
    consumption_actions: [
      { value: 'work', text: 'Расход в работу' },
      { value: 'complaint', text: 'Рекламация' },
      { value: 'defect', text: 'Брак' },
    ],
    work_order: {},
    future_works: [],
    works: [],
    future_nomenclatures: [],
    futureWorkForm: {
      workOrderId: null,
      description: '',
    },
    workForm: {
      id: 0,
      description: '',
      media: [],
      responsible: 0,
    },
    isEditModeForWork: false,
    isEditModeForFutureWork: false,
    isNomenclatureEnough: null,
    nomenclatureNotEnoughIds: [],
    rowsChecked: [],

    // consumption
    isLoadingСonsumption: false,
    currentWork: {},
    formСonsumptionType: Object.values(MATERIAL_CONSUMPTION_TYPE)[0].value,
    formСonsumptionTypeOptions: Object.values(MATERIAL_CONSUMPTION_TYPE),
    formСonsumptionEditMode: false,
    formFutureСonsumptionEditMode: false,

    isLoadingFutureConsumption: false,
    currentFutureWork: {},
    formFutureConsumptionEditMode: false,
    formFutureConsumption: {
      id: null,
      nomenclature_id: null,
      volume: null,
    },
    formFutureConsumptionNomenclatureSelected: null,

    tabIndex: 1,

    isConsumptionFormValid: false,
    showMediaGallery: false,
  }),
  computed: {
    futureConsumptionFormValid() {
      if (this.formFutureConsumption.volume <= 0 || !this.formFutureConsumption.nomenclature_id)
        return false
      return true
    },

    futureNomenclatureIds() {
      let nomenclature_ids = []
      this.future_works.forEach(w => {
        w.consumptions.forEach(c => {
          nomenclature_ids.push(c.nomenclature_id)
        })
      })
      return nomenclature_ids
    },

    showUserContact() {
      return this.$store.getters['auth/getUserRoles'].filter(role => ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN', 'ROLE_MANAGER', 'ROLE_MANAGER_JUNIOR', 'ROLE_DISPATCHER'].indexOf(role) > -1).length > 0
    },

    workFormValid() {
      return this.workForm.description != ''
    },
    formMediaImages() {
      return this.workForm.media.filter(fm => !fm.includes('mp4') && !fm.includes('mkv'))
    },
  },
  watch: {
    'work_order.status'(val) {
      this.tabIndex = WORK_ORDER_STATUS.filter(s => ['NEW', 'IS_PLANNED_WORK'].includes(s.code)).map(s => s.id).includes(val) ? 0 : 1
    },

    formFutureConsumptionNomenclatureSelected(val) {
      this.formFutureConsumption.nomenclature_id = val ? val.id : null
    },

    futureNomenclatureIds(val) {
      if (val.length)
        this.getFutureNomenclatures(val)
      else
        this.future_nomenclatures = []
    },

    future_nomenclatures() {
      if (!this.work_order.start_date) {
        this.isNomenclatureEnough = null
        return
      }

      let res = []
      this.future_nomenclatures.forEach(n => {
        if (this.isResourcesEndBeforeDate(n.end_of_resources_forecast_days, this.work_order.start_date))
          res.push(n.id)
      })
      this.nomenclatureNotEnoughIds = res
      this.isNomenclatureEnough = res.length === 0
    },
  },
  async mounted() {
    await this.getData()
    await this.getDataFutureWorks()
  },
  methods: {
    async getData() {
      this.isLoading = true
      try {
        const response = await this.axios.get(process.env.VUE_APP_BACKEND_URL + '/work-order/show-card/' + this.$route.params.id)
        this.work_order = response.data
        this.isLoading = false
        await this.getDataWorks()
      } catch (error) {
        this.isLoading = false
        console.log(error)
      }
    },

    async getDataWorks() {
      try {
        const response = await this.axios.get(process.env.VUE_APP_BACKEND_URL + '/work-order/show-works/' + this.$route.params.id)
        this.works = response.data

        const promises = response.data.map(e => this.axios.get(
          process.env.VUE_APP_BACKEND_URL + `/warehouse-work/${e.id}/consumptions`,
        ))
        const results = await Promise.allSettled(promises)

        results.forEach((result, idx) => {
          if (result.status === 'fulfilled') {
            this.$set(this.works[idx], 'consumptions', result.value.data)
          }
        })
      } catch (err) {
        console.log(err)
      }
    },

    async getDataFutureWorks() {
      try {
        const response = await this.axios.get(process.env.VUE_APP_BACKEND_URL + '/work-order/show-future-works/' + this.$route.params.id)
        this.future_works = response.data

        this.getDataWorks()

        /*const promises = response.data.map(e => this.axios.get(
          process.env.VUE_APP_BACKEND_URL + `/warehouse-work/${e.id}/consumptions`,
        ))
        const results = await Promise.allSettled(promises)

        results.forEach((result, idx) => {
          if (result.status === 'fulfilled') {
            this.$set(this.works[idx], 'consumptions', result.value.data)
          }
        })*/
      } catch (err) {
        console.log(err)
      }
    },

    async getFutureNomenclatures(nomenclatureIds) {
      try {
        const response = await this.axios.get(process.env.VUE_APP_BACKEND_URL + '/nomenclature?ids[]=' + nomenclatureIds.join('&ids[]='))
        this.future_nomenclatures = response.data
      }
      catch (err) {
        console.error('🐹 ', err)
        this.$root.$emit('global-error')
      }
    },

    submit() {
      this.isLoading = true
      this.$refs.form_consumption.submit()
    },

    toggleRemove() {
      const callback = async() => {
        try {
          const id = this.$route.params.id
          await this.axios.delete(process.env.VUE_APP_BACKEND_URL + `/work-order/${id}`)
          this.$router.push({ name: 'WorkOrdersList' })
        } catch (err) {
          console.error('🐹 ', err)
          this.$root.$emit('global-error')
        }
      }
      this.$root.$emit('global-confirm', callback)
    },

    toggleAddFutureWork() {
      this.isEditModeForFutureWork = false
      this.futureWorkForm.workOrderId = this.$route.params.id
      this.futureWorkForm.description = ''
      delete this.futureWorkForm.id
      this.$bvModal.show('modal-future-work')
    },

    async toggleEditFutureWork(item) {
      this.isEditModeForFutureWork = true
      this.futureWorkForm.workOrderId = this.$route.params.id
      this.futureWorkForm.description = item.description
      this.$set(this.futureWorkForm, 'id', item.id)
      this.$bvModal.show('modal-future-work')
    },

    async toggleRemoveFutureWork(item) {
      const callback = async() => {
        try {
          const id = item.id
          await this.axios.delete(process.env.VUE_APP_BACKEND_URL + `/work-order/future-work/${id}`)
          await this.getDataFutureWorks()
        } catch (err) {
          console.error('🐹 ', err)
          this.$root.$emit('global-error')
        }
      }
      this.$root.$emit('global-confirm', callback)
    },

    toggleFutureMaterialConsumption(work) {
      this.currentFutureWork = work
      this.formFutureConsumptionEditMode = false
      this.$root.$emit('bv::show::modal','material-future-consumption')
    },

    async onSubmitFutureConsumption() {
      this.isLoadingFutureConsumption = true
      try {
        if(this.formFutureConsumptionEditMode === true) {
          const id = this.formFutureConsumption.id
          await this.axios.put(process.env.VUE_APP_BACKEND_URL + `/warehouse-future-work/consumption/${id}`, this.formFutureConsumption)
        } else {
          const id = this.currentFutureWork.id
          await this.axios.post(process.env.VUE_APP_BACKEND_URL + `/warehouse-future-work/${id}/consumption/create`, this.formFutureConsumption)
        }
        await this.getDataFutureWorks()
        this.isLoadingFutureConsumption = false
        this.$bvModal.hide('material-future-consumption')
      }
      catch (err) {
        this.isLoadingFutureConsumption = false
        console.error('🐹 ', err)
        this.$root.$emit('global-error')
      }
    },

    toggleEditFutureConsumption(work, consumption) {
      this.currentFutureWork = work
      this.formFutureConsumptionEditMode = true
      this.$root.$emit('bv::show::modal','material-future-consumption')
      this.formFutureConsumption.id = consumption.id
      this.formFutureConsumption.volume = consumption.volume
      this.$set(this, 'formFutureConsumptionNomenclatureSelected', {
        id: consumption.nomenclature_id,
        text: consumption.nomenclature_name,
        unit_name: consumption.nomenclature_unit,
      })
    },

    toggleRemoveFutureConsumption(consumption) {
      const callback = async() => {
        try {
          const id = consumption.id
          await this.axios.delete(process.env.VUE_APP_BACKEND_URL + `/warehouse-future-work/consumption/${id}`)
          await this.getDataFutureWorks()
        } catch (err) {
          console.error('🐹 ', err)
          this.$root.$emit('global-error')
        }
      }
      this.$root.$emit('global-confirm', callback)
    },

    clearFutureConsumptionForm() {
      this.formFutureConsumption.nomenclature_id = null
      this.formFutureConsumption.volume = null
      this.formFutureConsumptionNomenclatureSelected = null
    },

    toggleAddWork() {
      this.isEditModeForWork = false
      this.workForm.media = []
      this.workForm.description = ''
      this.workForm.id = 0
      this.$bvModal.show('modal-work')
    },

    async onSubmitWork() {
      this.isLoading = true
      try {
        const data = {
          work_order_id: this.$route.params.id,
          description: this.workForm.description,
          media: this.workForm.media,
          responsible: this.workForm.responsible,
        }
        if (this.isEditModeForWork === true) {
          await this.axios.put(process.env.VUE_APP_BACKEND_URL + '/work-order/work/' + this.workForm.id, data)
        } else {
          await this.axios.post(process.env.VUE_APP_BACKEND_URL + '/work-order/work', data)
        }
        this.$bvModal.hide('modal-work')
        await this.getDataWorks()
        this.isLoading = false
        this.workForm.media = []
      } catch (err) {
        this.isLoading = false
        console.error('🐹 ', err)
        this.$root.$emit('global-error')
      }
    },

    async toggleEditWork(item) {
      this.isEditModeForWork = true
      try {
          const id = item.id
          const response = await this.axios.get(process.env.VUE_APP_BACKEND_URL + `/work-order/get-single-work/${id}`)
          this.workForm = response.data
          this.workForm.id = item.id
          this.$bvModal.show('modal-work')
        } catch (err) {
          console.error('🐹 ', err)
          this.$root.$emit('global-error')
        }
    },

    async toggleRemoveWork(item) {
      const callback = async() => {
        try {
          const id = item.id
          await this.axios.delete(process.env.VUE_APP_BACKEND_URL + `/work-order/work/${id}`)
          await this.getDataWorks()
        } catch (err) {
          console.error('🐹 ', err)
          this.$root.$emit('global-error')
        }
      }
      this.$root.$emit('global-confirm', callback)
    },

    showMaterialConsumption(work) {
      this.currentWork = work
      this.formСonsumptionEditMode = false
      this.formСonsumptionType = Object.values(MATERIAL_CONSUMPTION_TYPE)[0].value
      this.$root.$emit('bv::show::modal','material-consumption')
    },

    async onSubmitСonsumption() {
      this.isLoadingСonsumption = true
      const formСonsumption = this.$refs.warehouseItemConsumption.getFormValue()
      try {
        if(this.formСonsumptionEditMode === true) {
          const id = formСonsumption.id
          formСonsumption.warehouse_item_id = formСonsumption.warehouse_item.id
          await this.axios.post(process.env.VUE_APP_BACKEND_URL + `/warehouse-work/consumption/${id}`,formСonsumption)
        } else {
          const id = this.currentWork.id
          await this.axios.post(process.env.VUE_APP_BACKEND_URL + `/warehouse-work/${id}/consumption/create`, formСonsumption)
        }
        this.isLoadingСonsumption = false
        await this.getDataWorks()
        this.$bvModal.hide('material-consumption')
      } catch (err) {
        console.error('🐹', err)
        if (err.response?.data) {
          this.$root.$emit('global-error', err.response.data.message)
        } else {
          this.$root.$emit('global-error')
        }
        this.isLoadingСonsumption = false
      }
    },
    toggleRemoveConsumption(consumption) {
      const callback = async() => {
        try {
          const id = consumption.id
          await this.axios.delete(process.env.VUE_APP_BACKEND_URL + `/warehouse-work/consumption/${id}`)
          await this.getDataWorks()
        } catch (err) {
          console.error('🐹 ', err)
          this.$root.$emit('global-error')
        }
      }
      this.$root.$emit('global-confirm', callback)
    },
    toggleEditConsumption(work, consumption) {
      this.currentWork = work
      this.formСonsumptionEditMode = true
      this.formСonsumptionType = consumption.type
      this.$root.$emit('bv::show::modal','material-consumption')
      this.$nextTick(() => {
        this.$refs.warehouseItemConsumption.setFormValue(consumption)
      })
    },
    formatDate(date) {
      if (date) {
        const utc = require('dayjs/plugin/utc')
        dayjs.extend(utc)
        return dayjs.utc(date).local().format('DD.MM.YY')
      }
      return date
    },
    onConsumptionFormValidationChanged(val) {
      this.isConsumptionFormValid = val
    },
    async uploadFile(event) {
      this.isLoading = true
      try {
        const file = event.target.files[0]
        const formData = new FormData()
        formData.append('file', file)
        const response = await this.axios.post(process.env.VUE_APP_BACKEND_URL + '/work-order/upload', formData,
        {
          headers: {
            'Content-Type': 'multipart/form-data',
          },
        })
        this.workForm.media.push(response.data.url)
        this.isLoading = false
      } catch (err) {
        console.error('🐹', err)
        this.$root.$emit('global-error')
        this.isLoading = false
      }
    },
    removeImage(idx) {
      this.workForm.media.splice(idx, 1)
    },
    toggleShowMediaGallery() {
      this.showMediaGallery = !this.showMediaGallery
    },
  },
}
</script>


<style scoped lang="sass">
.abz-card-body
  max-width: 1680px
.soft-delete
  background-color: #f5c6cb
</style>
