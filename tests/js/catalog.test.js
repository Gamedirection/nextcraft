import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { appsFor, extensionRows, extOf, resolveHandler, specificAppsFor } from '../../src/shared/catalog.js'

const raw = JSON.parse(readFileSync(new URL('../../lib/catalog.json', import.meta.url)))
const catalog = {
	defaults: raw.defaults,
	apps: Object.fromEntries(Object.entries(raw.apps).map(([id, app]) => [id, { ...app, installed: true }])),
}

describe('extOf', () => {
	it('takes the last extension, lower-cased', () => {
		expect(extOf('Report.Final.DOCX')).toBe('docx')
		expect(extOf('.hidden')).toBe('')
		expect(extOf('noext')).toBe('')
	})
})

describe('appsFor', () => {
	it('lists explicit apps before catch-alls', () => {
		expect(appsFor(catalog, 'af')).toEqual(['photocraft', 'vectorcraft', 'craft-anything'])
		expect(specificAppsFor(catalog, 'af')).toEqual(['photocraft', 'vectorcraft'])
	})
	it('skips apps that are not installed', () => {
		const partial = { ...catalog, apps: { ...catalog.apps, vectorcraft: { ...catalog.apps.vectorcraft, installed: false } } }
		expect(specificAppsFor(partial, 'ai')).toEqual([])
	})
})

describe('resolveHandler', () => {
	it('asks for Office documents by default', () => {
		expect(resolveHandler(catalog, {}, 'docx', true)).toBe('ask')
	})
	it('follows the user choice', () => {
		expect(resolveHandler(catalog, { docx: 'wordcraft' }, 'docx', true)).toBe('wordcraft')
		expect(resolveHandler(catalog, { docx: 'builtin' }, 'docx', true)).toBe('builtin')
	})
	it('ignores a builtin choice when Nextcloud cannot open the file', () => {
		expect(resolveHandler(catalog, { indd: 'builtin' }, 'indd', false)).toBe('designcraft')
	})
	it('sends formats only a Craft app opens straight to it', () => {
		expect(resolveHandler(catalog, {}, 'ai', false)).toBe('vectorcraft')
		expect(resolveHandler(catalog, {}, 'indd', false)).toBe('designcraft')
	})
	it('leaves images and PDFs to Nextcloud unless the user chose otherwise', () => {
		expect(resolveHandler(catalog, {}, 'png', true)).toBe('builtin')
		expect(resolveHandler(catalog, {}, 'pdf', true)).toBe('builtin')
		expect(resolveHandler(catalog, { pdf: 'pdfcraft' }, 'pdf', true)).toBe('pdfcraft')
	})
	it('never claims files no Craft app names', () => {
		expect(resolveHandler(catalog, {}, 'zip', true)).toBe('builtin')
		expect(resolveHandler(catalog, {}, 'zip', false)).toBe('builtin')
	})
	it('asks when several apps fit and none is the default', () => {
		expect(resolveHandler(catalog, {}, 'svgz', false)).toBe('ask')
	})
	it('ignores a choice of an app that cannot open the type', () => {
		expect(resolveHandler(catalog, { ai: 'wordcraft' }, 'ai', false)).toBe('vectorcraft')
	})
})

describe('catalog', () => {
	it('defaults only name apps that open the extension', () => {
		for (const [ext, handler] of Object.entries(catalog.defaults)) {
			if (handler !== 'ask' && handler !== 'builtin') {
				expect(catalog.apps[handler].opens, `${ext} → ${handler}`).toContain(ext)
			}
		}
	})
	it('openOnly is a subset of opens', () => {
		for (const [id, app] of Object.entries(catalog.apps)) {
			for (const ext of app.openOnly) {
				expect(app.opens, id).toContain(ext)
			}
		}
	})
	it('has a row per extension', () => {
		const rows = extensionRows(catalog)
		expect(rows.find((r) => r.ext === 'af').apps).toEqual(['photocraft', 'vectorcraft'])
	})
})
