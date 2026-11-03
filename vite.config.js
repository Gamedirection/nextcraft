import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	files: 'src/files/init.js',
	editor: 'src/editor/main.js',
	admin: 'src/settings/admin.js',
	personal: 'src/settings/personal.js',
}, {
	inlineCSS: true,
	// The shim is built separately into js/ (vite.shim.config.js): keep it.
	emptyOutputDirectory: { additionalDirectories: [] },
	extractLicenseInformation: false,
	thirdPartyLicense: false,
})
