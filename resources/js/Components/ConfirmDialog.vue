<script setup>
import { confirmState, settleConfirm } from '../lib/confirm.js'
import Modal from './Modal.vue'
import Icon from './Icon.vue'
</script>

<template>
  <Modal :open="confirmState.open" :title="confirmState.title" size="sm" @close="settleConfirm(false)">
    <div class="flex gap-4">
      <span
        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
        :class="confirmState.tone === 'danger' ? 'bg-red-100 text-red-700' : 'bg-brand-100 text-brand-700'"
      >
        <Icon :name="confirmState.tone === 'danger' ? 'alert' : 'help'" :size="20" />
      </span>
      <div class="min-w-0 space-y-3 text-sm leading-relaxed text-ink-700">
        <p v-if="confirmState.message">{{ confirmState.message }}</p>
        <ul v-if="confirmState.details.length" class="space-y-1.5 rounded-xl bg-ink-50 p-3.5">
          <li v-for="(detail, index) in confirmState.details" :key="index" class="flex gap-2">
            <span class="text-ink-400">•</span>
            <span>{{ detail }}</span>
          </li>
        </ul>
        <label v-if="confirmState.input" class="block">
          <span class="label">{{ confirmState.input.label }}</span>
          <input
            v-model="confirmState.value"
            class="input mt-2"
            :placeholder="confirmState.input.placeholder"
            @keydown.enter.prevent="settleConfirm(true)"
          >
        </label>
      </div>
    </div>

    <template #footer>
      <button type="button" class="btn-secondary" @click="settleConfirm(false)">{{ confirmState.cancelLabel }}</button>
      <button
        type="button"
        :class="confirmState.tone === 'danger' ? 'btn-danger-solid' : 'btn-primary'"
        @click="settleConfirm(true)"
      >
        {{ confirmState.confirmLabel }}
      </button>
    </template>
  </Modal>
</template>
