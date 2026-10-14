<script setup>
// TinyMCE bundled with the app (no Tiny Cloud), so the editor also works offline.
// Load this component lazily: TinyMCE is large and only the report pages need it.
//
// Its styles are linked as CSS files, never injected as <style> blocks: the server's
// Content-Security-Policy (style-src 'self') blocks inline styles, and the editor would stay blank.
import { computed, onBeforeMount, onUnmounted, watch } from 'vue'
import 'tinymce'
import 'tinymce/models/dom'
import 'tinymce/themes/silver'
import 'tinymce/icons/default'

import skinLight from 'tinymce/skins/ui/oxide/skin.min.css?url'
import skinDark from 'tinymce/skins/ui/oxide-dark/skin.min.css?url'
// Selection outlines, table handles and the like inside the page (which stays white in both themes)
import uiContent from 'tinymce/skins/ui/oxide/content.min.css?url'

import 'tinymce/plugins/advlist'
import 'tinymce/plugins/charmap'
import 'tinymce/plugins/code'
import 'tinymce/plugins/fullscreen'
import 'tinymce/plugins/image'
import 'tinymce/plugins/link'
import 'tinymce/plugins/lists'
import 'tinymce/plugins/pagebreak'
import 'tinymce/plugins/searchreplace'
import 'tinymce/plugins/table'
import 'tinymce/plugins/visualblocks'

import Editor from '@tinymce/tinymce-vue'

const props = defineProps({
  init: { type: Object, required: true },
  dark: { type: Boolean, default: false },
})

const html = defineModel({ type: String, default: '' })
const emit = defineEmits(['init'])

// The page inside the editor is an iframe: its stylesheets need full addresses
const absolute = (url) => new URL(url, document.baseURI).href

// The editor's own look, switched with the app theme
let skinLink = null
function applySkin() {
  if (!skinLink) {
    skinLink = document.createElement('link')
    skinLink.rel = 'stylesheet'
    document.head.appendChild(skinLink)
  }
  skinLink.href = props.dark ? skinDark : skinLight
}
onBeforeMount(applySkin)
watch(() => props.dark, applySkin)
onUnmounted(() => skinLink?.remove())

const editorInit = computed(() => ({
  ...props.init,
  skin: false,
  content_css: [uiContent, ...[].concat(props.init.content_css || [])].filter(Boolean).map(absolute),
}))
</script>

<template>
  <Editor v-model="html" license-key="gpl" :init="editorInit" @init="emit('init')" />
</template>
