/**
 * The editor page. Frames the Craft app (same origin) and connects it to the user's files:
 * - apps with the ?host=parent bridge get the file and hand back saves over postMessage;
 * - every other app gets it through the NextCraft shim (js/nextcraft-shim.js, injected into the
 *   app's index.html at install), which drops the file onto the app and hands back its downloads
 *   and file dialogs through window.__nextcraftHost.
 * Saves overwrite the opened file when name and format match, otherwise the user picks a
 * Nextcloud folder or a download.
 */
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { extOf } from '../shared/catalog.js'
import { choose, saveDialog } from '../shared/modal.js'
import { ConflictError, Dav, LockedError, dirname, joinPath, quoteEtag } from './dav.js'

const config = loadState('nextcraft', 'editor')
const root = document.getElementById('nextcraft-editor')
const statusEl = root.querySelector('.nextcraft-editor__status')
const dav = new Dav(config.user?.id ?? '')

/** Files this session knows in Nextcloud, by name: the opened one, and any opened or saved since. */
const known = new Map()
/** The document the app is editing (saves under its name overwrite it). */
let current = null
let dirty = false
let lockState = 'none'

document.title = root.dataset.title || config.name

function setStatus(text) {
	statusEl.textContent = text
	statusEl.hidden = !text
}

function remember(file) {
	known.set(file.name, file)
	return file
}

function mimeFor(name) {
	const ext = extOf(name)
	return {
		pdf: 'application/pdf', docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		svg: 'image/svg+xml', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', dxf: 'image/vnd.dxf', txt: 'text/plain', md: 'text/markdown', csv: 'text/csv',
	}[ext] ?? 'application/octet-stream'
}

async function load() {
	if (!config.file) {
		return null
	}
	const file = config.file
	setStatus(t('nextcraft', 'Opening {name}…', { name: file.name }))
	const { bytes, etag } = await dav.read(file.path)
	current = remember({ name: file.name, path: file.path, etag: etag ?? quoteEtag(file.etag), canWrite: file.canWrite })
	if (file.canWrite) {
		lockState = await dav.lock(file.path)
		if (lockState === 'locked') {
			// Taking the lock changes the file's ETag.
			current.etag = (await dav.etag(file.path)) ?? current.etag
		} else if (lockState === 'held-by-other') {
			current.canWrite = false
			showWarning(t('nextcraft', '{name} is being edited by someone else. Your changes will be saved as a copy.', { name: file.name }), { timeout: -1 })
		}
	}
	return bytes
}

// ---- saving ---------------------------------------------------------------------------------

async function writeNew(folder, name, bytes) {
	const path = joinPath(folder, name)
	if (await dav.exists(path)) {
		const answer = await choose({
			title: t('nextcraft', '“{name}” already exists', { name }),
			options: [
				{ id: 'replace', label: t('nextcraft', 'Replace it'), primary: true },
				{ id: 'copy', label: t('nextcraft', 'Keep both') },
			],
		})
		if (answer === null) {
			return null
		}
		if (answer.id === 'copy') {
			return writeNew(folder, copyName(name), bytes)
		}
		const etag = await dav.write(path, bytes, { mime: mimeFor(name) })
		return remember({ name, path, etag, canWrite: true })
	}
	const etag = await dav.write(path, bytes, { create: true, mime: mimeFor(name) })
	return remember({ name, path, etag, canWrite: true })
}

function copyName(name) {
	const dot = name.lastIndexOf('.')
	const stamp = new Date().toISOString().slice(0, 16).replace('T', ' ').replace(':', '.')
	return dot > 0 ? `${name.slice(0, dot)} (${stamp})${name.slice(dot)}` : `${name} (${stamp})`
}

async function overwrite(file, bytes) {
	try {
		file.etag = await dav.write(file.path, bytes, { etag: file.etag, mime: mimeFor(file.name) })
		return file
	} catch (error) {
		if (!(error instanceof ConflictError) && !(error instanceof LockedError)) {
			throw error
		}
		const answer = await choose({
			title: error instanceof LockedError ? t('nextcraft', '“{name}” is locked', { name: file.name }) : t('nextcraft', '“{name}” was changed by someone else', { name: file.name }),
			text: t('nextcraft', 'Your version can be saved as a copy next to it, or replace theirs.'),
			options: [
				{ id: 'copy', label: t('nextcraft', 'Save as a copy'), primary: true },
				...(error instanceof ConflictError ? [{ id: 'overwrite', label: t('nextcraft', 'Replace their version') }] : []),
			],
		})
		if (answer === null) {
			return null
		}
		if (answer.id === 'overwrite') {
			file.etag = await dav.write(file.path, bytes, { mime: mimeFor(file.name) })
			return file
		}
		return writeNew(dirname(file.path), copyName(file.name), bytes)
	}
}

function download(name, bytes) {
	const url = URL.createObjectURL(new Blob([bytes], { type: mimeFor(name) }))
	const a = document.createElement('a')
	a.href = url
	a.download = name
	document.body.append(a)
	HTMLAnchorElement.prototype.click.call(a)
	a.remove()
	setTimeout(() => URL.revokeObjectURL(url), 30_000)
}

/**
 * A save from the app. Same name as a known Nextcloud file (and not an explicit Save As):
 * overwrite it. Otherwise ask: Nextcloud folder or device.
 * @param {{name: string, bytes: ArrayBuffer, saveAs?: boolean}} save
 * @return {Promise<boolean>} whether the bytes went somewhere
 */
async function save({ name, bytes, saveAs = false }) {
	const target = known.get(name)
	if (target && !saveAs && target.canWrite !== false) {
		const saved = await overwrite(target, bytes)
		if (saved) {
			showSuccess(t('nextcraft', 'Saved {name}', { name: saved.name }))
			current = saved
			return true
		}
		return false
	}
	const original = current ?? (config.file ? { name: config.file.name, path: config.file.path } : null)
	const note = original && extOf(name) !== extOf(original.name) && config.openOnly.includes(extOf(original.name))
		? t('nextcraft', '{app} can’t write .{ext} files, so this is saved in another format. The original stays as it is.', { app: config.name, ext: extOf(original.name) })
		: undefined
	const choice = await saveDialog({
		name,
		folder: original ? dirname(original.path) : '/',
		pickFolder: (start) => dav.pickFolder(start),
		note,
	})
	if (choice === null) {
		return false
	}
	if (choice.target === 'device') {
		download(choice.name, bytes)
		return true
	}
	const saved = await writeNew(choice.folder, choice.name, bytes)
	if (saved) {
		current = saved
		showSuccess(t('nextcraft', 'Saved to {path}', { path: saved.path }))
		return true
	}
	return false
}

async function guardedSave(request) {
	try {
		return await save(request)
	} catch (error) {
		console.error('NextCraft: save failed', error)
		showError(error.message || t('nextcraft', 'Could not save'))
		return false
	}
}

// ---- opening from inside the app (shim) -----------------------------------------------------

/**
 * The app asked for a file (its Open/Place dialog). Resolves to {files: File[]} from Nextcloud,
 * 'device' to let the browser's own picker run, or null when cancelled.
 */
async function pickOpen({ accept = '' } = {}) {
	const exts = accept.split(',').map((s) => s.trim().replace(/^\./, '').toLowerCase()).filter((s) => /^[a-z0-9]+$/.test(s))
	const choice = await choose({
		title: t('nextcraft', 'Open a file'),
		options: [
			{ id: 'nextcloud', label: t('nextcraft', 'From Nextcloud'), primary: true },
			{ id: 'device', label: t('nextcraft', 'From this device') },
		],
	})
	if (choice === null) {
		return null
	}
	if (choice.id === 'device') {
		return 'device'
	}
	const path = await dav.pickFile(exts)
	if (!path) {
		return null
	}
	const name = path.split('/').pop()
	const { bytes, etag } = await dav.read(path)
	remember({ name, path, etag, canWrite: true })
	return { files: [new File([bytes], name, { type: mimeFor(name) })] }
}

// ---- the frame ------------------------------------------------------------------------------

function frameUrl() {
	const url = new URL(config.appUrl, window.location.href)
	if (config.bridge === 'native') {
		url.searchParams.set('host', 'parent')
		if (config.user?.name) {
			url.searchParams.set('author', config.user.name)
		}
	}
	return url.href
}

async function start() {
	let bytes = null
	try {
		bytes = await load()
	} catch (error) {
		setStatus(error.message)
		return
	}
	setStatus(t('nextcraft', 'Starting {app}…', { app: config.name }))

	const frame = document.createElement('iframe')
	frame.className = 'nextcraft-editor__frame'
	frame.title = config.name
	frame.allow = 'fullscreen; clipboard-read; clipboard-write'
	frame.setAttribute('allowfullscreen', '')

	window.__nextcraftHost = {
		config: { app: config.app, bridge: config.bridge, assetUrl: config.assetUrl, liveServer: config.liveServer },
		save: (request) => guardedSave(request),
		pickOpen: (request) => pickOpen(request).catch((error) => {
			showError(error.message)
			return null
		}),
		setDirty: (value) => {
			dirty = Boolean(value)
		},
		/** The shim is up: hand it the document once the app can take a drop. */
		attach: async (shim) => {
			if (bytes !== null && config.bridge !== 'native') {
				await shim.whenReady()
				shim.open(new File([bytes], current.name, { type: mimeFor(current.name) }))
			}
			setStatus('')
		},
	}

	if (config.bridge === 'native') {
		const prefix = config.app + ':'
		window.addEventListener('message', (event) => {
			if (event.origin !== window.location.origin || event.source !== frame.contentWindow) {
				return
			}
			const data = event.data
			if (!data || typeof data.type !== 'string' || !data.type.startsWith(prefix)) {
				return
			}
			switch (data.type.slice(prefix.length)) {
			case 'ready':
				setStatus('')
				if (bytes !== null) {
					frame.contentWindow.postMessage({ type: prefix + 'open', name: current.name, bytes }, window.location.origin)
				}
				break
			case 'save':
				guardedSave({ name: data.name, bytes: data.bytes, saveAs: Boolean(data.saveAs) })
				break
			case 'dirty':
				dirty = Boolean(data.dirty)
				break
			case 'failed':
				showError(t('nextcraft', '{app}: {error}', { app: config.name, error: String(data.error ?? '') }))
				break
			}
		})
	}

	frame.src = frameUrl()
	root.append(frame)
	frame.focus()
}

window.addEventListener('beforeunload', (event) => {
	if (dirty) {
		event.preventDefault()
	}
})
window.addEventListener('pagehide', () => {
	if (lockState === 'locked' && config.file) {
		dav.unlock(config.file.path)
	}
})

start()
