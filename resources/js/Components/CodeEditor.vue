<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { EditorView, keymap, lineNumbers, highlightActiveLine, drawSelection } from '@codemirror/view'
import { EditorState } from '@codemirror/state'
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands'
import { sql } from '@codemirror/lang-sql'
import { syntaxHighlighting, defaultHighlightStyle, indentOnInput } from '@codemirror/language'

const props = defineProps({
  modelValue: { type: String, default: '' },
  height: { type: String, default: '16rem' },
})

// `run`: Ctrl/Cmd + Enter, jalan pintas untuk "Coba jalankan".
const emit = defineEmits(['update:modelValue', 'run'])

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
        drawSelection(),
        indentOnInput(),
        highlightActiveLine(),
        keymap.of([
          { key: 'Mod-Enter', run: () => { emit('run'); return true } },
          ...defaultKeymap, ...historyKeymap, indentWithTab,
        ]),
        sql(),
        syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
        EditorView.lineWrapping,
        EditorView.theme({
          '&': { borderRadius: '0.75rem', border: '1px solid #cbd5e1', overflow: 'hidden' },
          '&.cm-focused': { borderColor: '#6366f1', boxShadow: '0 0 0 4px rgb(99 102 241 / 0.15)' },
          '.cm-scroller': { minHeight: props.height, fontFamily: 'var(--font-mono)', fontSize: '14px', lineHeight: '1.6' },
          '.cm-content': { padding: '12px 0' },
        }),
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
  <div ref="host" />
</template>
