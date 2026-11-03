/**
 * NextCraft shim, injected as the first script of every Craft app's index.html at install.
 * It does nothing unless the page is framed by a NextCraft editor page on the same origin
 * (window.parent.__nextcraftHost). Then:
 * - assets the web server won't serve directly (Nextcloud's .htaccess 404s unknown extensions)
 *   are fetched again through NextCraft's asset route;
 * - FigCraft gets the configured live session server (<meta name="figcraft-server">);
 * - for apps without the ?host=parent bridge: downloads (<a download> with a blob) become
 *   NextCraft saves, the app's file dialog (rfd) offers Nextcloud files, and the editor can open
 *   a document by dropping it onto the app's canvas, which every Craft app accepts.
 */
;(function () {
	let host = null
	try {
		host = window.parent !== window ? window.parent.__nextcraftHost : null
	} catch {
		host = null
	}
	if (!host) {
		return
	}
	const config = host.config

	// ---- assets the web server won't serve -------------------------------------------------
	const base = new URL('.', window.location.href)
	const assetBase = new URL(config.assetUrl.replace(/_$/, ''), window.location.href)
	const realFetch = window.fetch.bind(window)
	window.fetch = async function (input, init) {
		const response = await realFetch(input, init)
		if (response.status !== 404) {
			return response
		}
		try {
			const url = new URL(typeof input === 'string' || input instanceof URL ? input : input.url, window.location.href)
			if (url.origin === base.origin && url.pathname.startsWith(base.pathname)) {
				const fallback = new URL(url.pathname.slice(base.pathname.length), assetBase)
				const second = await realFetch(fallback.href, init)
				if (second.ok) {
					return second
				}
			}
		} catch {
			// keep the original 404
		}
		return response
	}

	// ---- FigCraft live sessions ------------------------------------------------------------
	if (config.liveServer) {
		const meta = document.createElement('meta')
		meta.name = 'figcraft-server'
		meta.content = config.liveServer
		document.head.append(meta)
	}

	if (config.bridge === 'native') {
		return
	}

	// ---- downloads become saves ------------------------------------------------------------
	async function captureDownload(anchor) {
		const name = anchor.download || 'download'
		const blob = await (await realFetch(anchor.href)).blob()
		return host.save({ name, bytes: await blob.arrayBuffer() })
	}
	const isCapturable = (a) => a instanceof HTMLAnchorElement && a.hasAttribute('download') && /^(blob|data):/.test(a.href)

	const anchorClick = HTMLAnchorElement.prototype.click
	HTMLAnchorElement.prototype.click = function () {
		if (isCapturable(this)) {
			captureDownload(this)
			return
		}
		return anchorClick.call(this)
	}
	// rfd's save dialog shows a real link the user clicks.
	document.addEventListener('click', (event) => {
		const a = event.target instanceof Element ? event.target.closest('a') : null
		if (a && isCapturable(a)) {
			event.preventDefault()
			event.stopPropagation()
			captureDownload(a)
		}
	}, true)

	// ---- file dialogs offer Nextcloud ------------------------------------------------------
	const inputClick = HTMLInputElement.prototype.click
	const handled = new WeakSet()

	function feed(input, files) {
		const transfer = new DataTransfer()
		files.forEach((file) => transfer.items.add(file))
		input.files = transfer.files
		input.dispatchEvent(new Event('input', { bubbles: true }))
		input.dispatchEvent(new Event('change', { bubbles: true }))
	}

	async function offer(input) {
		const answer = await host.pickOpen({ accept: input.accept || '', multiple: input.multiple })
		if (answer === 'device') {
			// The click on "From this device" counts as user activation for the browser's picker.
			inputClick.call(input)
		} else if (answer && answer.files?.length) {
			feed(input, answer.files)
		} else {
			// rfd rejects its dialog (and removes its popup) on the input's cancel event.
			input.dispatchEvent(new Event('cancel'))
		}
	}

	HTMLInputElement.prototype.click = function () {
		if (this.type === 'file' && !handled.has(this)) {
			handled.add(this)
			offer(this).finally(() => handled.delete(this))
			return
		}
		return inputClick.call(this)
	}

	// Without user activation rfd shows its own popup instead of clicking the input: answer it.
	new MutationObserver((mutations) => {
		for (const mutation of mutations) {
			for (const node of mutation.addedNodes) {
				if (node instanceof HTMLElement && node.id === 'rfd-overlay') {
					const input = node.querySelector('input[type=file]')
					if (input && !handled.has(input) && !node.classList.contains('hidden')) {
						node.classList.add('hidden')
						handled.add(input)
						offer(input).finally(() => handled.delete(input))
					}
				}
			}
		}
	}).observe(document.documentElement, { childList: true, subtree: true })

	// ---- opening the document --------------------------------------------------------------
	const frame = () => new Promise((resolve) => requestAnimationFrame(() => resolve()))
	const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

	/** Resolves once the app has a canvas and its loading screen is gone. */
	async function whenReady() {
		const deadline = Date.now() + 120_000
		for (;;) {
			const canvas = document.querySelector('canvas')
			const loading = [...document.querySelectorAll('[id*="loading"], [class*="loading"]')]
				.some((el) => el.offsetParent !== null)
			if (canvas && canvas.width > 0 && !loading) {
				break
			}
			if (Date.now() > deadline) {
				break
			}
			await sleep(200)
		}
		await frame()
		await frame()
		await sleep(800)
	}

	function open(file) {
		const canvas = document.querySelector('canvas') ?? document.body
		const rect = canvas.getBoundingClientRect()
		const transfer = new DataTransfer()
		transfer.items.add(file)
		const at = { clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2 }
		for (const type of ['dragenter', 'dragover', 'drop']) {
			canvas.dispatchEvent(new DragEvent(type, { dataTransfer: transfer, bubbles: true, cancelable: true, ...at }))
		}
		canvas.focus?.()
	}

	const attach = () => host.attach({ whenReady, open })
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', attach, { once: true })
	} else {
		attach()
	}
})()
