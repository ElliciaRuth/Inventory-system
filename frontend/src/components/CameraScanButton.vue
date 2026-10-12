<script setup>
import { ref, nextTick, onUnmounted } from 'vue'
import { Camera, X, Loader2 } from 'lucide-vue-next'

// A camera button for a search box: opens the camera, reads one barcode and emits its text,
// which the box then handles like a USB scanner (the code followed by Enter).
// Uses the browser's own barcode reader where there is one (Android Chrome), otherwise ZXing,
// bundled with the app so it works offline.
defineProps({
  title: { type: String, default: 'Scan a barcode with the camera' },
})
const emit = defineEmits(['scanned'])

// Batch and finished-product labels are Code 128; product barcodes on stockcards are Code 39
const NATIVE_FORMATS = ['code_128', 'code_39']
const SCAN_EVERY_MS = 150

const open = ref(false)
const starting = ref(false)
const error = ref('')
const video = ref(null)

let stream = null
let timer = null
let detect = null
let lastRead = ''

function onKeydown(event) {
  if (event.key === 'Escape') stop()
}

async function start() {
  open.value = true
  window.addEventListener('keydown', onKeydown)
  error.value = ''
  lastRead = ''
  starting.value = true

  if (!window.isSecureContext) {
    return fail('The camera only works on the secure address of the app (it starts with https://). Open the app that way and try again.')
  }
  if (!navigator.mediaDevices?.getUserMedia) {
    return fail('This browser cannot use the camera. Try Chrome or Safari.')
  }

  try {
    stream = await navigator.mediaDevices.getUserMedia({
      audio: false,
      video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
    })
  } catch (err) {
    return fail(cameraError(err))
  }

  // Closed while the permission prompt was up
  if (!open.value) return stop()

  await nextTick()
  video.value.srcObject = stream
  try {
    await video.value.play()
    detect = await createDetector()
  } catch {
    return fail('The camera could not be started. Close the scanner and try again.')
  }
  starting.value = false
  scheduleScan()
}

async function createDetector() {
  if ('BarcodeDetector' in window) {
    const supported = await window.BarcodeDetector.getSupportedFormats().catch(() => [])
    const formats = NATIVE_FORMATS.filter((f) => supported.includes(f))
    if (formats.length === NATIVE_FORMATS.length) {
      const detector = new window.BarcodeDetector({ formats })
      return async (el) => (await detector.detect(el))[0]?.rawValue || ''
    }
  }

  const [{ BrowserMultiFormatReader }, { BarcodeFormat, DecodeHintType }] = await Promise.all([
    import('@zxing/browser'),
    import('@zxing/library'),
  ])
  const hints = new Map([
    [DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.CODE_128, BarcodeFormat.CODE_39]],
    [DecodeHintType.TRY_HARDER, true],
  ])
  const reader = new BrowserMultiFormatReader(hints)
  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d', { willReadFrequently: true })

  // Only the band inside the guide box: faster, and fewer stray reads
  return async (el) => {
    const w = el.videoWidth
    const h = el.videoHeight
    if (!w || !h) return ''
    const cropW = Math.round(w * 0.85)
    const cropH = Math.round(h * 0.4)
    canvas.width = cropW
    canvas.height = cropH
    ctx.drawImage(el, (w - cropW) / 2, (h - cropH) / 2, cropW, cropH, 0, 0, cropW, cropH)
    try {
      return reader.decodeFromCanvas(canvas).getText()
    } catch {
      return '' // nothing in this frame
    }
  }
}

function scheduleScan() {
  timer = setTimeout(scanFrame, SCAN_EVERY_MS)
}

async function scanFrame() {
  if (!open.value || !detect || !video.value) return
  let text = ''
  try {
    text = String(await detect(video.value)).trim()
  } catch {
    text = ''
  }
  if (!open.value) return

  // The same code twice in a row, so a half-seen label is not taken as a different code
  if (text && text === lastRead) {
    navigator.vibrate?.(60)
    stop()
    emit('scanned', text)
    return
  }
  lastRead = text
  scheduleScan()
}

function cameraError(err) {
  switch (err?.name) {
    case 'NotAllowedError':
    case 'SecurityError':
      return 'Camera access was blocked. Allow the camera for this site in the browser settings, then try again.'
    case 'NotFoundError':
    case 'OverconstrainedError':
      return 'No camera was found on this device.'
    case 'NotReadableError':
      return 'The camera is being used by another app. Close it and try again.'
    default:
      return 'The camera could not be started.'
  }
}

function fail(message) {
  error.value = message
  starting.value = false
  releaseCamera()
}

function releaseCamera() {
  clearTimeout(timer)
  timer = null
  detect = null
  stream?.getTracks().forEach((t) => t.stop())
  stream = null
  if (video.value) video.value.srcObject = null
}

function stop() {
  window.removeEventListener('keydown', onKeydown)
  releaseCamera()
  open.value = false
  starting.value = false
}

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
  releaseCamera()
})
</script>

<template>
  <button type="button" class="cam-btn" :title="title" :aria-label="title" @mousedown.prevent @click="start">
    <Camera :size="17" />
  </button>

  <Teleport to="body">
    <div v-if="open" class="cam-overlay" role="dialog" aria-modal="true" aria-label="Scan a barcode">
      <div class="cam-top">
        <span>Point the camera at a barcode</span>
        <button type="button" class="cam-close" aria-label="Close scanner" @click="stop"><X :size="20" /></button>
      </div>

      <div class="cam-stage">
        <video ref="video" class="cam-video" playsinline muted autoplay></video>
        <div v-if="!error" class="cam-guide" aria-hidden="true"><span class="cam-line"></span></div>

        <div v-if="starting && !error" class="cam-status">
          <Loader2 :size="22" class="cam-spin" /> Starting camera…
        </div>
        <div v-if="error" class="cam-status cam-error">
          <p>{{ error }}</p>
          <button type="button" class="btn btn-secondary btn-sm" @click="stop">Close</button>
        </div>
      </div>

      <p class="cam-hint">Hold the label flat inside the box, about a hand's width away.</p>
    </div>
  </Teleport>
</template>

<style scoped>
.cam-btn {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: 0;
  border-radius: var(--radius-sm);
  background: none;
  color: var(--color-primary);
  cursor: pointer;
}

.cam-btn:hover {
  background: var(--color-primary-light);
}

.cam-overlay {
  position: fixed;
  inset: 0;
  z-index: 1500;
  display: flex;
  flex-direction: column;
  background: #000;
  color: #fff;
}

.cam-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 1rem;
  padding-top: max(0.75rem, env(safe-area-inset-top));
  font-weight: 600;
}

.cam-close {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border: 0;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.15);
  color: #fff;
  cursor: pointer;
}

.cam-stage {
  position: relative;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

.cam-video {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* Guide box over the middle band that is read */
.cam-guide {
  position: absolute;
  left: 50%;
  top: 50%;
  width: min(85%, 520px);
  height: min(40%, 220px);
  transform: translate(-50%, -50%);
  border: 3px solid rgba(255, 255, 255, 0.9);
  border-radius: 14px;
  box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.45);
}

.cam-line {
  position: absolute;
  left: 6%;
  right: 6%;
  top: 50%;
  height: 2px;
  background: #ef4444;
  box-shadow: 0 0 8px #ef4444;
  animation: cam-sweep 1.6s ease-in-out infinite alternate;
}

@keyframes cam-sweep {
  from { transform: translateY(-40px); }
  to { transform: translateY(40px); }
}

.cam-status {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 1.5rem;
  text-align: center;
  background: rgba(0, 0, 0, 0.6);
}

.cam-error p {
  max-width: 380px;
  margin: 0;
  line-height: 1.5;
}

.cam-spin {
  animation: cam-rotate 0.8s linear infinite;
}

@keyframes cam-rotate {
  to { transform: rotate(360deg); }
}

.cam-hint {
  margin: 0;
  padding: 0.85rem 1rem;
  padding-bottom: max(0.85rem, env(safe-area-inset-bottom));
  text-align: center;
  font-size: 0.85rem;
  color: rgba(255, 255, 255, 0.8);
}

@media (prefers-reduced-motion: reduce) {
  .cam-line {
    animation: none;
  }
}
</style>
