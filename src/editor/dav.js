/**
 * The user's files over WebDAV, with the session cookie and request token: read with ETag,
 * conditional write, user locks (files_lock) and the folder picker.
 */
import { getRequestToken } from '@nextcloud/auth'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateRemoteUrl } from '@nextcloud/router'

export class ConflictError extends Error {}
export class LockedError extends Error {}

export function dirname(path) {
	const i = path.lastIndexOf('/')
	return i <= 0 ? '/' : path.slice(0, i)
}

export function joinPath(folder, name) {
	return (folder.endsWith('/') ? folder : folder + '/') + name
}

export class Dav {
	constructor(uid) {
		this.root = generateRemoteUrl('dav') + '/files/' + encodeURIComponent(uid)
	}

	url(path) {
		return this.root + path.split('/').map(encodeURIComponent).join('/')
	}

	headers(extra = {}) {
		return { requesttoken: getRequestToken() ?? '', 'X-Requested-With': 'XMLHttpRequest', ...extra }
	}

	/** @return {Promise<{bytes: ArrayBuffer, etag: string|null}>} */
	async read(path) {
		const response = await fetch(this.url(path), { headers: this.headers(), credentials: 'same-origin' })
		if (!response.ok) {
			throw new Error(t('nextcraft', 'Could not load the file (HTTP {status})', { status: response.status }))
		}
		return { bytes: await response.arrayBuffer(), etag: etagOf(response) }
	}

	/**
	 * Writes `bytes` to `path`. With `etag`, only if the file is unchanged (else ConflictError);
	 * with `create`, only if it does not exist yet (else ConflictError).
	 * @return {Promise<string|null>} the new ETag
	 */
	async write(path, bytes, { etag = null, create = false, mime = 'application/octet-stream' } = {}) {
		const headers = this.headers({ 'Content-Type': mime })
		if (etag) {
			headers['If-Match'] = etag
		}
		if (create) {
			headers['If-None-Match'] = '*'
		}
		const response = await fetch(this.url(path), { method: 'PUT', headers, body: bytes, credentials: 'same-origin' })
		if (response.status === 412) {
			throw new ConflictError(path)
		}
		if (response.status === 423) {
			throw new LockedError(path)
		}
		if (!response.ok) {
			throw new Error(t('nextcraft', 'Could not save (HTTP {status})', { status: response.status }))
		}
		return etagOf(response) ?? (await this.etag(path))
	}

	async etag(path) {
		const response = await fetch(this.url(path), {
			method: 'PROPFIND',
			headers: this.headers({ Depth: '0', 'Content-Type': 'application/xml' }),
			body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>',
			credentials: 'same-origin',
		})
		const text = response.ok ? await response.text() : ''
		return quoteEtag(/<d:getetag>([^<]*)<\/d:getetag>/.exec(text)?.[1] ?? null)
	}

	async exists(path) {
		const response = await fetch(this.url(path), { method: 'HEAD', headers: this.headers(), credentials: 'same-origin' })
		return response.ok
	}

	/** Takes a user lock (files_lock app). Resolves to 'locked', 'held-by-other' or 'unsupported'. */
	async lock(path) {
		try {
			const response = await fetch(this.url(path), { method: 'LOCK', headers: this.headers({ 'X-User-Lock': '1' }), credentials: 'same-origin' })
			if (response.ok) {
				return 'locked'
			}
			return response.status === 423 ? 'held-by-other' : 'unsupported'
		} catch {
			return 'unsupported'
		}
	}

	unlock(path) {
		return fetch(this.url(path), { method: 'UNLOCK', headers: this.headers({ 'X-User-Lock': '1' }), credentials: 'same-origin', keepalive: true }).catch(() => {})
	}

	/** Folder picker; resolves to the chosen folder path or null. */
	async pickFolder(start) {
		let chosen = null
		await getFilePickerBuilder(t('nextcraft', 'Choose a folder'))
			.allowDirectories(true)
			.setMimeTypeFilter(['httpd/unix-directory'])
			.setMultiSelect(false)
			.startAt(start)
			.addButton({ label: t('nextcraft', 'Choose'), variant: 'primary', callback: (nodes) => { chosen = nodes[0]?.path ?? '/' } })
			.build()
			.pickNodes()
			.catch(() => null)
		return chosen
	}

	/** File picker limited to `exts` (empty = any); resolves to the chosen file's path or null. */
	async pickFile(exts) {
		let chosen = null
		const builder = getFilePickerBuilder(t('nextcraft', 'Open from Nextcloud'))
			.setMultiSelect(false)
			.allowDirectories(false)
			.addButton({ label: t('nextcraft', 'Open'), variant: 'primary', callback: (nodes) => { chosen = nodes[0]?.path ?? null } })
		if (exts.length > 0) {
			builder.setFilter((node) => node.type === 'folder' || exts.includes((node.extension ?? '').replace(/^\./, '').toLowerCase()))
		}
		await builder.build().pickNodes().catch(() => null)
		return chosen
	}
}

function etagOf(response) {
	return quoteEtag(response.headers.get('ETag') ?? response.headers.get('OC-ETag'))
}

/** ETags as If-Match wants them: quoted ("abc"). Nextcloud's OC-ETag and PROPFIND XML differ. */
export function quoteEtag(etag) {
	if (!etag) {
		return null
	}
	const value = etag.replace(/^W\//, '').replace(/&quot;/g, '"').trim()
	return value.startsWith('"') ? value : `"${value}"`
}
