<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { EditorView, keymap, lineNumbers, highlightActiveLine } from '@codemirror/view'
import { EditorState } from '@codemirror/state'
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands'
import { sql } from '@codemirror/lang-sql'
import { syntaxHighlighting, defaultHighlightStyle } from '@codemirror/language'

const props = defineProps({
  modelValue: { type: String, default: '' },
  height: { type: String, default: '18rem' },
  placeholder: { type: String, default: 'SELECT ... FROM APPS.TABEL' },
})

const emit = defineEmits(['update:modelValue'])

const host = ref(null)
let view = null

onMounted(() => {
  view = new EditorView({
    parent: host.value,
    state: EditorState.create({
      doc: props.modelValue,
      extensions: [
        lineNumbers(),
        history(),
        keymap.of([...defaultKeymap, ...historyKeymap]),
        highlightActiveLine(),
        sql(),
        syntaxHighlighting(defaultHighlightStyle),
        EditorView.lineWrapping,
        EditorView.updateListener.of((update) => {
          if (update.docChanged) {
            emit('update:modelValue', update.state.doc.toString())
          }
        }),
        EditorState.tabSize.of(2),
      ],
    }),
  })
})

onBeforeUnmount(() => {
  view?.destroy()
  view = null
})

watch(
  () => props.modelValue,
  (value) => {
    if (!view) return

    const current = view.state.doc.toString()

    if (current !== value) {
      view.dispatch({ changes: { from: 0, to: current.length, insert: value } })
    }
  },
)
</script>

<template>
  <div
    ref="host"
    class="overflow-hidden rounded-lg border border-slate-300 bg-white text-sm [&_.cm-editor]:outline-none [&_.cm-editor_cm-frame]:px-3 [&_.cm-editor_cm-frame]:py-2"
    :style="{ minHeight: height }"
  />
</template>
