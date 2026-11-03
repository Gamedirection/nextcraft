/** Personal settings ▸ NextCraft: which app opens each file type. */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { extensionRows } from '../shared/catalog.js'

const root = document.getElementById('nextcraft-personal')
const catalog = loadState('nextcraft', 'catalog', { apps: {}, defaults: {} })
let prefs = loadState('nextcraft', 'prefs', {})

function h(tag, attrs = {}, ...children) {
	const node = document.createElement(tag)
	for (const [key, value] of Object.entries(attrs)) {
		if (key.startsWith('on')) {
			node.addEventListener(key.slice(2), value)
		} else if (key === 'class') {
			node.className = value
		} else if (value !== false && value != null) {
			node.setAttribute(key, value === true ? '' : value)
		}
	}
	node.append(...children.flat().filter((c) => c != null && c !== false))
	return node
}

function defaultLabel(ext) {
	const d = catalog.defaults[ext]
	if (d === 'ask') {
		return t('nextcraft', 'Ask each time')
	}
	if (d && catalog.apps[d]) {
		return catalog.apps[d].name
	}
	return t('nextcraft', 'Nextcloud, if it can open it')
}

async function set(ext, handler) {
	try {
		const { data } = await axios.post(generateUrl('/apps/nextcraft/prefs'), { ext, handler })
		prefs = data.prefs
		showSuccess(t('nextcraft', 'Saved'))
	} catch (error) {
		showError(error.response?.data?.error ?? error.message)
	}
}

function render() {
	const filter = h('input', { type: 'search', placeholder: t('nextcraft', 'Filter file types…'), class: 'nextcraft-personal__filter' })
	const rows = extensionRows(catalog).filter(({ apps }) => apps.some((id) => catalog.apps[id].installed))
	const tbody = h('tbody', {}, rows.map(({ ext, apps }) => {
		const select = h('select', { 'aria-label': '.' + ext, onchange: (e) => set(ext, e.target.value) },
			h('option', { value: '' }, t('nextcraft', 'Default ({choice})', { choice: defaultLabel(ext) })),
			h('option', { value: 'ask' }, t('nextcraft', 'Ask each time')),
			h('option', { value: 'builtin' }, t('nextcraft', 'Nextcloud (Office, viewer…)')),
			apps.filter((id) => catalog.apps[id].installed).map((id) => h('option', { value: id }, catalog.apps[id].name)))
		select.value = prefs[ext] ?? ''
		return h('tr', { 'data-ext': ext }, h('td', {}, '.' + ext), h('td', {}, select))
	}))
	filter.addEventListener('input', () => {
		const q = filter.value.trim().toLowerCase().replace(/^\./, '')
		for (const row of tbody.children) {
			row.hidden = q !== '' && !row.dataset.ext.includes(q)
		}
	})
	root.replaceChildren(
		h('h2', {}, t('nextcraft', 'Default apps')),
		h('p', { class: 'settings-hint' }, t('nextcraft', 'Choose what opens when you click a file in Files. “Open with…” in the file menu always lists every app.')),
		...(rows.length === 0
			? [h('p', {}, t('nextcraft', 'No Craft apps are installed yet.'))]
			: [filter, h('table', { class: 'grid nextcraft-personal__table' }, h('thead', {}, h('tr', {}, h('th', {}, t('nextcraft', 'File type')), h('th', {}, t('nextcraft', 'Opens in')))), tbody)]),
	)
}

render()
