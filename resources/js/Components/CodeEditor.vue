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
        drawSelection(),
        indentOnInput(),
        highlightActiveLine(),
        keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),
        sql(),
        syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
        EditorView.lineWrapping,
        EditorView.theme({
          '&': { borderRadius: '0.5rem', border: '1px solid #cbd5e1', overflow: 'hidden' },
          '&.cm-focused': { borderColor: '#3b68f5', boxShadow: '0 0 0 3px rgb(59 104 245 / 0.12)' },
          '.cm-scroller': { minHeight: props.height, fontFamily: 'var(--font-mono)', fontSize: '13px' },
          '.cm-content': { padding: '10px 0' },
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
