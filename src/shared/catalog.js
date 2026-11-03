/**
 * Routing rules shared by the Files actions and the settings pages.
 * A handler is 'ask', 'builtin' (Nextcloud's own viewer/editor) or a Craft app id.
 */

/** Lower-case extension of a file name, '' when there is none. */
export function extOf(name) {
	const dot = name.lastIndexOf('.')
	return dot > 0 ? name.slice(dot + 1).toLowerCase() : ''
}

/** Installed Craft apps that open `ext`, apps naming it explicitly first, then catch-alls. */
export function appsFor(catalog, ext) {
	const explicit = []
	const wildcard = []
	for (const [id, app] of Object.entries(catalog.apps)) {
		if (!app.installed) {
			continue
		}
		if (app.opens.includes(ext)) {
			explicit.push(id)
		} else if (app.opens.includes('*')) {
			wildcard.push(id)
		}
	}
	return [...explicit, ...wildcard]
}

/** Apps that name `ext` (not catch-alls): these decide whether NextCraft claims the click. */
export function specificAppsFor(catalog, ext) {
	return appsFor(catalog, ext).filter((id) => !catalog.apps[id].opens.includes('*'))
}

/**
 * The handler a click on a .ext file uses. `builtinAvailable` says whether Nextcloud itself can
 * open the file (Viewer, Collabora, Text…).
 */
export function resolveHandler(catalog, prefs, ext, builtinAvailable) {
	const apps = specificAppsFor(catalog, ext)
	const valid = (h) => h === 'ask' || (h === 'builtin' && builtinAvailable) || apps.includes(h)
	if (apps.length === 0) {
		return 'builtin'
	}
	const chosen = prefs[ext]
	if (chosen && valid(chosen)) {
		return chosen
	}
	const fallback = catalog.defaults[ext]
	if (fallback && valid(fallback)) {
		return fallback
	}
	if (builtinAvailable) {
		return fallback === undefined ? 'builtin' : 'ask'
	}
	return apps.length === 1 ? apps[0] : 'ask'
}

/** Groups extensions by the set of apps that open them, for the settings table. */
export function extensionRows(catalog) {
	const rows = new Map()
	for (const [id, app] of Object.entries(catalog.apps)) {
		for (const ext of app.opens) {
			if (ext === '*') {
				continue
			}
			if (!rows.has(ext)) {
				rows.set(ext, [])
			}
			rows.get(ext).push(id)
		}
	}
	return [...rows.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([ext, apps]) => ({ ext, apps }))
}
