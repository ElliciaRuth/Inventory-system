<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/authStore'
import { ROLE_INTROS, CATEGORIES, TOPICS, FAQS } from '../help/helpContent'
import { BookOpen, Search, ChevronDown, ArrowRight, Lightbulb, MessageCircleQuestion, X } from 'lucide-vue-next'

const authStore = useAuthStore()
const router = useRouter()

// Technical staff are level 4; anything unexpected falls back to the Staff guide
const level = computed(() => Math.min(Math.max(authStore.levelId || 1, 1), 4))
const intro = computed(() => ROLE_INTROS[level.value])

const search = ref('')
const category = ref('all')
const open = ref(new Set())

const myTopics = computed(() => TOPICS.filter((t) => t.levels.includes(level.value)))
const myFaqs = computed(() => FAQS.filter((f) => f.levels.includes(level.value)))
const myCategories = computed(() => CATEGORIES.filter((c) => myTopics.value.some((t) => t.category === c.key)))

const query = computed(() => search.value.trim().toLowerCase())
const matches = (texts) => !query.value || texts.join(' ').toLowerCase().includes(query.value)

const visibleTopics = computed(() =>
  myTopics.value.filter(
    (t) =>
      (category.value === 'all' || t.category === category.value) &&
      matches([t.title, t.summary, ...(t.steps || []), ...(t.tips || [])])
  )
)
const groupedTopics = computed(() =>
  myCategories.value
    .map((c) => ({ ...c, topics: visibleTopics.value.filter((t) => t.category === c.key) }))
    .filter((g) => g.topics.length)
)
const visibleFaqs = computed(() =>
  category.value === 'all' || category.value === 'faq' ? myFaqs.value.filter((f) => matches([f.q, f.a])) : []
)
const nothingFound = computed(() => !groupedTopics.value.length && !visibleFaqs.value.length)

// While searching, every match is shown expanded
const isOpen = (id) => !!query.value || open.value.has(id)
function toggle(id) {
  const next = new Set(open.value)
  next.has(id) ? next.delete(id) : next.add(id)
  open.value = next
}
const allOpen = computed(() => visibleTopics.value.length > 0 && visibleTopics.value.every((t) => open.value.has(t.id)))
function toggleAll() {
  open.value = allOpen.value ? new Set() : new Set(visibleTopics.value.map((t) => t.id))
}

// "Open page" only for pages this user may open (mirrors the router's level rules)
function canOpen(to) {
  if (!to) return false
  const target = router.resolve(to)
  if (!target.matched.length || target.name === undefined) return false
  const { minLevel, maxLevel } = target.meta
  return !((minLevel && level.value < minLevel) || (maxLevel && level.value > maxLevel))
}

function pickCategory(key) {
  category.value = key
}
</script>

<template>
  <div class="help-page">
    <!-- Hero -->
    <div class="page-hero help-hero">
      <div>
        <p class="hero-eyebrow"><BookOpen :size="14" /> Help &amp; User Guide</p>
        <h1 class="hero-title">How to use the system</h1>
        <p class="hero-subtitle">
          Step-by-step guides for your account. Only what your role can do is shown here.
        </p>
      </div>
      <div class="help-role-card">
        <span class="help-role-label">Your role</span>
        <strong class="help-role-name">{{ intro.role }}</strong>
        <span class="help-role-level">Level {{ level }} · {{ authStore.officeName }}</span>
      </div>
    </div>

    <!-- What this role does -->
    <section class="panel help-intro">
      <p>{{ intro.text }}</p>
    </section>

    <!-- Search & categories -->
    <div class="help-toolbar">
      <label class="help-search">
        <Search :size="16" />
        <input v-model="search" type="search" placeholder="Search the guide, e.g. password, barcode, backup…" aria-label="Search the guide" />
        <button v-if="search" type="button" class="help-search-clear" aria-label="Clear search" @click="search = ''">
          <X :size="14" />
        </button>
      </label>
      <button v-if="visibleTopics.length" type="button" class="btn btn-secondary btn-sm" @click="toggleAll">
        {{ allOpen ? 'Collapse all' : 'Expand all' }}
      </button>
    </div>
    <div class="help-chips" role="tablist" aria-label="Guide sections">
      <button type="button" role="tab" class="help-chip" :class="{ 'is-active': category === 'all' }" :aria-selected="category === 'all'" @click="pickCategory('all')">
        All
      </button>
      <button
        v-for="c in myCategories"
        :key="c.key"
        type="button"
        role="tab"
        class="help-chip"
        :class="{ 'is-active': category === c.key }"
        :aria-selected="category === c.key"
        @click="pickCategory(c.key)"
      >
        {{ c.label }}
      </button>
      <button type="button" role="tab" class="help-chip" :class="{ 'is-active': category === 'faq' }" :aria-selected="category === 'faq'" @click="pickCategory('faq')">
        Common Questions
      </button>
    </div>

    <!-- Topics -->
    <section v-for="group in groupedTopics" :key="group.key" class="help-group">
      <h2 class="help-group-title">{{ group.label }}</h2>
      <article v-for="t in group.topics" :key="t.id" class="help-topic" :class="{ 'is-open': isOpen(t.id) }">
        <button
          type="button"
          class="help-topic-head"
          :aria-expanded="isOpen(t.id)"
          :aria-controls="`help-${t.id}`"
          @click="toggle(t.id)"
        >
          <span class="help-topic-icon"><component :is="t.icon" :size="18" /></span>
          <span class="help-topic-text">
            <strong>{{ t.title }}</strong>
            <small>{{ t.summary }}</small>
          </span>
          <ChevronDown :size="18" class="help-topic-chevron" />
        </button>

        <div v-show="isOpen(t.id)" :id="`help-${t.id}`" class="help-topic-body">
          <ol class="help-steps">
            <li v-for="(step, i) in t.steps" :key="i">{{ step }}</li>
          </ol>
          <div v-if="t.tips?.length" class="help-tips">
            <Lightbulb :size="15" />
            <ul>
              <li v-for="(tip, i) in t.tips" :key="i">{{ tip }}</li>
            </ul>
          </div>
          <router-link v-if="canOpen(t.to)" :to="t.to" class="btn btn-primary btn-sm help-open">
            {{ t.linkLabel || 'Open page' }} <ArrowRight :size="14" />
          </router-link>
        </div>
      </article>
    </section>

    <!-- FAQ -->
    <section v-if="visibleFaqs.length" class="help-group">
      <h2 class="help-group-title"><MessageCircleQuestion :size="16" /> Common Questions</h2>
      <div class="help-faqs">
        <div v-for="(f, i) in visibleFaqs" :key="i" class="help-faq">
          <strong>{{ f.q }}</strong>
          <p>{{ f.a }}</p>
        </div>
      </div>
    </section>

    <div v-if="nothingFound" class="panel help-empty">
      <strong>Nothing in the guide matches "{{ search }}".</strong>
      <p>Try another word, or pick "All" above.</p>
    </div>

    <p class="help-footer">
      Still stuck? Ask your office manager{{ level === 4 ? ' or another technical staff member' : '' }}.
    </p>
  </div>
</template>

<style scoped>
.help-page {
  max-width: 980px;
  margin: 0 auto;
}

.help-hero .hero-eyebrow {
  gap: 0.4rem;
}

.help-role-card {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  text-align: right;
  min-width: 180px;
  padding: 1rem 1.4rem;
  background: var(--bg-subtle);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
}
.help-role-label {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-muted);
}
.help-role-name {
  font-family: var(--font-display);
  font-size: 1.5rem;
  font-weight: 800;
  color: var(--color-primary);
  line-height: 1.2;
}
.help-role-level {
  font-size: 0.78rem;
  color: var(--text-subtle);
}

.help-intro {
  padding: 1.1rem 1.5rem;
  margin-bottom: 1.25rem;
  border-left: 4px solid var(--color-primary);
}
.help-intro p {
  color: var(--text-muted);
  line-height: 1.6;
  font-size: 0.95rem;
}

/* ── Toolbar ── */
.help-toolbar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}
.help-search {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0 0.9rem;
  height: 44px;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
  color: var(--text-muted);
  transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
}
.help-search:focus-within {
  border-color: var(--border-focus);
  box-shadow: 0 0 0 3px var(--color-primary-light);
}
.help-search input {
  flex: 1;
  min-width: 0;
  border: none;
  outline: none;
  background: transparent;
  color: var(--text-main);
  font-size: 0.92rem;
}
.help-search-clear {
  display: flex;
  border: none;
  background: var(--bg-muted);
  color: var(--text-muted);
  border-radius: 50%;
  padding: 3px;
  cursor: pointer;
}

.help-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 1.5rem;
}
.help-chip {
  padding: 0.4rem 0.9rem;
  border-radius: var(--radius-full);
  border: 1px solid var(--border-subtle);
  background: var(--bg-surface);
  color: var(--text-muted);
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  transition: all var(--transition-fast);
}
.help-chip:hover {
  border-color: var(--border-hover);
  color: var(--text-main);
}
.help-chip.is-active {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: #fff;
}

/* ── Topics ── */
.help-group {
  margin-bottom: 1.75rem;
}
.help-group-title {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.8rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-bottom: 0.65rem;
}

.help-topic {
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  margin-bottom: 0.6rem;
  overflow: hidden;
  transition: border-color var(--transition-fast);
}
.help-topic.is-open {
  border-color: var(--border-hover);
}
.help-topic-head {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.9rem;
  padding: 0.95rem 1.1rem;
  background: none;
  border: none;
  text-align: left;
  cursor: pointer;
  color: var(--text-main);
}
.help-topic-head:hover {
  background: var(--bg-subtle);
}
.help-topic-icon {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: var(--radius-sm);
  background: var(--color-primary-light);
  color: var(--color-primary);
}
.help-topic-text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.help-topic-text strong {
  font-size: 0.97rem;
}
.help-topic-text small {
  font-size: 0.82rem;
  color: var(--text-muted);
}
.help-topic-chevron {
  flex-shrink: 0;
  color: var(--text-subtle);
  transition: transform var(--transition-normal);
}
.help-topic.is-open .help-topic-chevron {
  transform: rotate(180deg);
}

.help-topic-body {
  padding: 0.25rem 1.25rem 1.2rem 4.15rem;
}
.help-steps {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding-left: 1.1rem;
  color: var(--text-main);
  font-size: 0.9rem;
  line-height: 1.55;
}
.help-steps li::marker {
  color: var(--color-primary);
  font-weight: 700;
}

.help-tips {
  display: flex;
  gap: 0.6rem;
  margin-top: 0.9rem;
  padding: 0.75rem 0.9rem;
  border-radius: var(--radius-sm);
  background: var(--color-warning-bg);
  color: var(--color-warning);
}
.help-tips svg {
  margin-top: 2px;
}
.help-tips ul {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.84rem;
  line-height: 1.5;
  color: var(--text-main);
}

.help-open {
  margin-top: 1rem;
}

/* ── FAQ ── */
.help-faqs {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}
.help-faq {
  padding: 1rem 1.1rem;
  background: var(--bg-surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-md);
}
.help-faq strong {
  display: block;
  font-size: 0.92rem;
  margin-bottom: 0.35rem;
}
.help-faq p {
  font-size: 0.86rem;
  line-height: 1.55;
  color: var(--text-muted);
}

.help-empty {
  padding: 1.75rem;
  text-align: center;
}
.help-empty p {
  color: var(--text-muted);
  margin-top: 0.3rem;
  font-size: 0.9rem;
}

.help-footer {
  text-align: center;
  font-size: 0.85rem;
  color: var(--text-subtle);
  margin-top: 0.5rem;
}

@media (max-width: 768px) {
  .help-role-card {
    align-items: flex-start;
    text-align: left;
    width: 100%;
  }
  .help-faqs {
    grid-template-columns: 1fr;
  }
  .help-topic-body {
    padding: 0.25rem 1rem 1.1rem;
  }
}
</style>
