/** Administration settings ▸ NextCraft: install, update, roll back and remove apps; options. */
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const root = document.getElementById('nextcraft-admin')
const url = (path) => generateUrl('/apps/nextcraft/admin' + path)
let state = { apps: {}, settings: {} }
const busy = new Set()

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

async function refresh(check = false) {
	const { data } = await axios.get(url('/status'), { params: { check } })
	state = data
	render()
}

async function act(id, request, done) {
	busy.add(id)
	render()
	try {
		const { data } = await request()
		showSuccess(done(data))
		await refresh(true)
	} catch (error) {
		showError(error.response?.data?.error ?? error.message)
	} finally {
		busy.delete(id)
		render()
	}
}

const install = (id) => act(id, () => axios.post(url('/apps/' + id)), (d) => t('nextcraft', '{app} {version} installed', { app: state.apps[id].name, version: d.version }))
const rollback = (id) => act(id, () => axios.post(url('/apps/' + id), { rollback: true }), (d) => t('nextcraft', '{app} is back on {version}', { app: state.apps[id].name, version: d.version }))
const remove = (id) => act(id, () => axios.delete(url('/apps/' + id)), () => t('nextcraft', '{app} removed', { app: state.apps[id].name }))

async function installAll(onlyUpdates) {
	for (const [id, app] of Object.entries(state.apps)) {
		const wanted = onlyUpdates ? app.installed && app.latest && app.latest !== app.installed : !app.installed
		if (wanted) {
			await install(id)
		}
	}
}

async function saveSettings(patch) {
	try {
		const { data } = await axios.post(url('/settings'), patch)
		state.settings = data
		showSuccess(t('nextcraft', 'Saved'))
		render()
	} catch (error) {
		showError(error.response?.data?.error ?? error.message)
	}
}

function appRow(id, app) {
	const working = busy.has(id)
	const update = app.installed && app.latest && app.latest !== app.installed
	const status = app.error
		? h('span', { class: 'nextcraft-admin__error' }, app.error)
		: app.installed
			? (update ? t('nextcraft', 'Update available: {version}', { version: app.latest }) : t('nextcraft', 'Up to date'))
			: (app.latest ? t('nextcraft', 'Latest: {version}', { version: app.latest }) : '')
	return h('tr', {},
		h('td', {}, h('strong', {}, app.name), h('br'), h('small', {}, app.description)),
		h('td', {}, app.installed ?? '—'),
		h('td', {}, status),
		h('td', { class: 'nextcraft-admin__actions' },
			working ? h('span', { class: 'icon-loading-small' }) : [
				!app.installed && h('button', { onclick: () => install(id) }, t('nextcraft', 'Install')),
				update && h('button', { class: 'primary', onclick: () => install(id) }, t('nextcraft', 'Update')),
				app.previous && h('button', { onclick: () => rollback(id), title: t('nextcraft', 'Go back to {version}', { version: app.previous }) }, t('nextcraft', 'Roll back')),
				app.installed && h('button', { onclick: () => remove(id) }, t('nextcraft', 'Remove')),
			]),
		h('td', {}, h('a', { href: 'https://github.com/' + app.repo, target: '_blank', rel: 'noopener noreferrer' }, 'GitHub ↗')))
}

function render() {
	const s = state.settings
	const liveInput = h('input', { type: 'text', value: s.liveServer ?? '', placeholder: 'wss://example.com/nextcraft-live/', class: 'nextcraft-admin__wide' })
	const tokenInput = h('input', { type: 'password', placeholder: s.githubToken ? t('nextcraft', 'A token is set') : 'github_pat_…', autocomplete: 'off', class: 'nextcraft-admin__wide' })
	root.replaceChildren(
		h('h2', {}, t('nextcraft', 'NextCraft')),
		h('p', { class: 'settings-hint' }, t('nextcraft', 'Craft apps run in the browser on this Nextcloud’s own address. Each one is downloaded from its official GitHub release and checked against the release checksums.')),
		h('div', { class: 'nextcraft-admin__toolbar' },
			h('button', { onclick: () => refresh(true) }, t('nextcraft', 'Check for updates')),
			h('button', { onclick: () => installAll(false) }, t('nextcraft', 'Install all')),
			h('button', { class: 'primary', onclick: () => installAll(true) }, t('nextcraft', 'Update all'))),
		h('table', { class: 'grid nextcraft-admin__table' },
			h('thead', {}, h('tr', {}, ...[t('nextcraft', 'App'), t('nextcraft', 'Installed'), t('nextcraft', 'Status'), '', ''].map((x) => h('th', {}, x)))),
			h('tbody', {}, Object.entries(state.apps).map(([id, app]) => appRow(id, app)))),
		h('h3', {}, t('nextcraft', 'Updates')),
		h('p', {},
			h('input', { type: 'checkbox', id: 'nextcraft-auto', class: 'checkbox', checked: s.autoUpdate, onchange: (e) => saveSettings({ autoUpdate: e.target.checked }) }),
			h('label', { for: 'nextcraft-auto' }, t('nextcraft', 'Install new releases automatically (checked daily by the background job)'))),
		h('h3', {}, t('nextcraft', 'Live sessions (FigCraft)')),
		h('p', { class: 'settings-hint' }, t('nextcraft', 'Address of a figcraft-server reachable by your users over wss://. Leave empty to use FigCraft’s default.')),
		h('p', {}, liveInput, h('button', { onclick: () => saveSettings({ liveServer: liveInput.value }) }, t('nextcraft', 'Save'))),
		h('h3', {}, t('nextcraft', 'GitHub token (optional)')),
		h('p', { class: 'settings-hint' }, t('nextcraft', 'Without a token GitHub allows 60 release lookups per hour from this server. A token with no scopes raises that to 5000.')),
		h('p', {}, tokenInput,
			h('button', { onclick: () => saveSettings({ githubToken: tokenInput.value }) }, t('nextcraft', 'Save')),
			s.githubToken && h('button', { onclick: () => saveSettings({ githubToken: '' }) }, t('nextcraft', 'Clear'))),
	)
}

refresh(false).then(() => refresh(true)).catch((error) => showError(error.message))
