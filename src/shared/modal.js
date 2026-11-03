/**
 * Small dependency-free dialogs styled with Nextcloud's CSS variables, usable both in Files and
 * on the editor page. Each returns a promise; Escape or the backdrop resolve with null.
 */
import { t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'

/** Loads css/modal.css once (the Files page doesn't pick up styles bundled with init scripts). */
function ensureStyles() {
	if (!document.getElementById('nextcraft-modal-css')) {
		const link = document.createElement('link')
		link.id = 'nextcraft-modal-css'
		link.rel = 'stylesheet'
		link.href = generateFilePath('nextcraft', 'css', 'modal.css')
		document.head.append(link)
	}
}

function el(tag, attrs = {}, ...children) {
	const node = document.createElement(tag)
	for (const [key, value] of Object.entries(attrs)) {
		if (key === 'class') {
			node.className = value
		} else if (key.startsWith('on')) {
			node.addEventListener(key.slice(2), value)
		} else if (value !== false && value !== undefined && value !== null) {
			node.setAttribute(key, value === true ? '' : value)
		}
	}
	for (const child of children.flat()) {
		if (child !== null && child !== undefined && child !== false) {
			node.append(child instanceof Node ? child : document.createTextNode(String(child)))
		}
	}
	return node
}

function open(build) {
	ensureStyles()
	return new Promise((resolve) => {
		const previous = document.activeElement
		let done = false
		const close = (value) => {
			if (done) {
				return
			}
			done = true
			backdrop.remove()
			document.removeEventListener('keydown', onKey, true)
			previous?.focus?.()
			resolve(value)
		}
		const onKey = (event) => {
			if (event.key === 'Escape') {
				event.stopPropagation()
				close(null)
			}
		}
		const dialog = build(close)
		const backdrop = el('div', { class: 'nextcraft-modal', onmousedown: (e) => e.target === backdrop && close(null) }, dialog)
		document.body.append(backdrop)
		document.addEventListener('keydown', onKey, true)
		;(dialog.querySelector('[autofocus]') ?? dialog.querySelector('button'))?.focus()
	})
}

/**
 * A list of choices.
 * @param {object} o
 * @param {string} o.title
 * @param {string} [o.text]
 * @param {{id: string, label: string, description?: string, icon?: string, primary?: boolean}[]} o.options
 * @param {string} [o.checkbox] label of an optional checkbox (e.g. "Always use this")
 * @return {Promise<{id: string, checked: boolean}|null>}
 */
export function choose({ title, text, options, checkbox }) {
	return open((close) => {
		const box = checkbox ? el('input', { type: 'checkbox', id: 'nextcraft-modal-check' }) : null
		return el('div', { class: 'nextcraft-modal__dialog', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
			el('h2', { class: 'nextcraft-modal__title' }, title),
			text ? el('p', { class: 'nextcraft-modal__text' }, text) : null,
			el('div', { class: 'nextcraft-modal__options' }, options.map((option, i) =>
				el('button', {
					class: 'nextcraft-modal__option' + (option.primary ? ' nextcraft-modal__option--primary' : ''),
					autofocus: i === 0,
					onclick: () => close({ id: option.id, checked: box?.checked ?? false }),
				},
				option.icon ? el('img', { class: 'nextcraft-modal__icon', src: option.icon, alt: '' }) : null,
				el('span', { class: 'nextcraft-modal__label' }, option.label,
					option.description ? el('small', {}, option.description) : null)))),
			box ? el('label', { class: 'nextcraft-modal__check', for: 'nextcraft-modal-check' }, box, checkbox) : null,
			el('div', { class: 'nextcraft-modal__actions' },
				el('button', { class: 'nextcraft-modal__cancel', onclick: () => close(null) }, t('nextcraft', 'Cancel'))))
	})
}

/**
 * The save dialog: Nextcloud folder + file name, or download to the device.
 * @param {object} o
 * @param {string} o.name proposed file name
 * @param {string} o.folder proposed Nextcloud folder ('/' = root)
 * @param {(folder: string) => Promise<string|null>} o.pickFolder opens the folder picker
 * @param {string} [o.note] extra explanation (e.g. format can't be saved back)
 * @return {Promise<{target: 'nextcloud', folder: string, name: string}|{target: 'device', name: string}|null>}
 */
export function saveDialog({ name, folder, pickFolder, note }) {
	return open((close) => {
		let currentFolder = folder
		const folderLabel = el('span', { class: 'nextcraft-modal__folder' }, currentFolder)
		const nameInput = el('input', { type: 'text', value: name, autofocus: true, 'aria-label': t('nextcraft', 'File name'), spellcheck: 'false' })
		const valid = () => {
			const value = nameInput.value.trim()
			return value !== '' && !/[\\/]/.test(value) && value !== '.' && value !== '..'
		}
		const submit = () => valid() && close({ target: 'nextcloud', folder: currentFolder, name: nameInput.value.trim() })
		nameInput.addEventListener('keydown', (e) => e.key === 'Enter' && submit())
		// Select the base name, keep the extension.
		setTimeout(() => {
			const dot = nameInput.value.lastIndexOf('.')
			nameInput.setSelectionRange(0, dot > 0 ? dot : nameInput.value.length)
		})
		return el('div', { class: 'nextcraft-modal__dialog', role: 'dialog', 'aria-modal': 'true', 'aria-label': t('nextcraft', 'Save') },
			el('h2', { class: 'nextcraft-modal__title' }, t('nextcraft', 'Save to…')),
			note ? el('p', { class: 'nextcraft-modal__text' }, note) : null,
			el('label', { class: 'nextcraft-modal__field' }, t('nextcraft', 'File name'), nameInput),
			el('div', { class: 'nextcraft-modal__field' }, t('nextcraft', 'Nextcloud folder'),
				el('div', { class: 'nextcraft-modal__row' }, folderLabel,
					el('button', {
						onclick: async () => {
							const picked = await pickFolder(currentFolder)
							if (picked) {
								currentFolder = picked
								folderLabel.textContent = picked
							}
						},
					}, t('nextcraft', 'Change…')))),
			el('div', { class: 'nextcraft-modal__actions' },
				el('button', { class: 'nextcraft-modal__cancel', onclick: () => close(null) }, t('nextcraft', 'Cancel')),
				el('button', { onclick: () => valid() && close({ target: 'device', name: nameInput.value.trim() }) }, t('nextcraft', 'Download to device')),
				el('button', { class: 'nextcraft-modal__option--primary', onclick: submit }, t('nextcraft', 'Save to Nextcloud'))))
	})
}
