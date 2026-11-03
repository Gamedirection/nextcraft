import { createAppConfig } from '@nextcloud/vite-config'

// One build per entry (NC_ENTRY): with several entries in one build, CSS shared between them
// (e.g. @nextcloud/dialogs) is injected by only one of them. See scripts in package.json.
const entries = {
	files: 'src/files/init.js',
	editor: 'src/editor/main.js',
	admin: 'src/settings/admin.js',
	personal: 'src/settings/personal.js',
}
const entry = process.env.NC_ENTRY
if (!entries[entry]) {
	throw new Error(`Set NC_ENTRY to one of: ${Object.keys(entries).join(', ')}`)
}

export default createAppConfig({ [entry]: entries[entry] }, {
	inlineCSS: true,
	emptyOutputDirectory: false,
	extractLicenseInformation: false,
	thirdPartyLicense: false,
	config: {
		build: { chunkSizeWarningLimit: 2000 },
	},
})
