import { defineConfig } from 'vite'

// The shim runs as a classic script at the top of each Craft app's <head>, before the app's
// own module: one self-contained IIFE with no imports.
export default defineConfig({
	build: {
		outDir: 'js',
		emptyOutDir: false,
		target: 'es2020',
		minify: true,
		sourcemap: false,
		lib: {
			entry: 'src/shim/shim.js',
			formats: ['iife'],
			name: 'NextCraftShim',
			fileName: () => 'nextcraft-shim.js',
		},
	},
})
