/**
 * Files integration: a default click action that follows the user's choice per extension (ask,
 * Nextcloud's own viewer/editor, or a Craft app) and an "Open with…" submenu listing every option.
 */
import axios from '@nextcloud/axios'
import { getCapabilities } from '@nextcloud/capabilities'
import { DefaultType, FileType, registerFileAction } from '@nextcloud/files'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { appsFor, extOf, resolveHandler, specificAppsFor } from '../shared/catalog.js'
import { choose } from '../shared/modal.js'

const catalog = loadState('nextcraft', 'catalog', { apps: {}, defaults: {} })
const prefs = loadState('nextcraft', 'prefs', {})

const OPEN_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3m-2 16H5V5h7V3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7h-2v7Z"/></svg>'
const APP_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2 2 7l10 5 10-5-10-5Zm0 7.24L5.24 7 12 3.76 18.76 7 12 9.24ZM2 17l10 5 10-5-2.24-1.12L12 19.76 4.24 15.88 2 17Zm0-5 10 5 10-5-2.24-1.12L12 14.76 4.24 10.88 2 12Z"/></svg>'

const single = (nodes) => nodes.length === 1 && nodes[0].type === FileType.File
const extOfNode = (node) => extOf(node.basename)

/** Whether Nextcloud's own Viewer (Collabora, Text, PDF viewer…) handles this file. */
function builtinAvailable(node) {
	return Boolean(window.OCA?.Viewer?.mimetypes?.includes(node.mime))
}

/** A name for Nextcloud's own handler of this file. */
function builtinLabel(node) {
	const office = getCapabilities()?.richdocuments?.mimetypes ?? []
	return office.includes(node.mime) ? t('nextcraft', 'Nextcloud Office') : t('nextcraft', 'Nextcloud viewer')
}

function openBuiltin(node) {
	window.OCA.Viewer.open({ path: node.path })
}

function openInCraft(app, node) {
	const url = generateUrl('/apps/nextcraft/edit/{app}', { app }) + '?path=' + encodeURIComponent(node.path)
	window.open(url, '_blank', 'noopener')
}

async function remember(ext, handler) {
	prefs[ext] = handler
	try {
		await axios.post(generateUrl('/apps/nextcraft/prefs'), { ext, handler })
	} catch (error) {
		console.error('NextCraft: could not save the default app', error)
	}
}

/** The chooser for .ext: every Craft app that opens it, plus Nextcloud's own handler. */
async function ask(node) {
	const ext = extOfNode(node)
	const options = appsFor(catalog, ext).map((id) => ({
		id,
		label: catalog.apps[id].name,
		description: catalog.apps[id].description,
	}))
	if (builtinAvailable(node)) {
		options.push({ id: 'builtin', label: builtinLabel(node), description: t('nextcraft', 'Open with Nextcloud') })
	}
	const choice = await choose({
		title: t('nextcraft', 'Open “{name}” with…', { name: node.basename }),
		options,
		checkbox: t('nextcraft', 'Always use this app for .{ext} files', { ext }),
	})
	if (choice === null) {
		return null
	}
	if (choice.checked) {
		await remember(ext, choice.id)
	}
	if (choice.id === 'builtin') {
		openBuiltin(node)
	} else {
		openInCraft(choice.id, node)
	}
	return null
}

// The click action. Ranked before the Viewer's "view" (order 0), so it wins whenever it is
// enabled; it disables itself when the user's choice is Nextcloud's own handler.
registerFileAction({
	id: 'nextcraft-open',
	displayName: () => t('nextcraft', 'Open'),
	iconSvgInline: () => OPEN_ICON,
	order: -10,
	default: DefaultType.DEFAULT,
	enabled: ({ nodes }) => {
		if (!single(nodes)) {
			return false
		}
		const node = nodes[0]
		return resolveHandler(catalog, prefs, extOfNode(node), builtinAvailable(node)) !== 'builtin'
	},
	exec: async ({ nodes }) => {
		const node = nodes[0]
		const handler = resolveHandler(catalog, prefs, extOfNode(node), builtinAvailable(node))
		if (handler === 'ask') {
			return ask(node)
		}
		openInCraft(handler, node)
		return null
	},
})

// "Open with…" submenu.
registerFileAction({
	id: 'nextcraft-open-with',
	displayName: () => t('nextcraft', 'Open with…'),
	iconSvgInline: () => OPEN_ICON,
	order: 24,
	enabled: ({ nodes }) => single(nodes) && appsFor(catalog, extOfNode(nodes[0])).length > 0,
	exec: async () => null,
})

registerFileAction({
	id: 'nextcraft-open-with-builtin',
	parent: 'nextcraft-open-with',
	displayName: ({ nodes }) => builtinLabel(nodes[0]),
	iconSvgInline: () => OPEN_ICON,
	order: 100,
	enabled: ({ nodes }) => single(nodes) && builtinAvailable(nodes[0]) && specificAppsFor(catalog, extOfNode(nodes[0])).length > 0,
	exec: async ({ nodes }) => {
		openBuiltin(nodes[0])
		return null
	},
})

Object.entries(catalog.apps).forEach(([id, app], index) => {
	if (!app.installed) {
		return
	}
	const catchAll = app.opens.includes('*')
	registerFileAction({
		id: `nextcraft-open-with-${id}`,
		parent: 'nextcraft-open-with',
		displayName: () => app.name,
		title: () => app.description,
		iconSvgInline: () => APP_ICON,
		order: catchAll ? 200 : index,
		enabled: ({ nodes }) => single(nodes) && (catchAll || app.opens.includes(extOfNode(nodes[0]))),
		exec: async ({ nodes }) => {
			openInCraft(id, nodes[0])
			return null
		},
	})
})
