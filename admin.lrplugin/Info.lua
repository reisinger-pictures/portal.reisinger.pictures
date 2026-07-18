return {
    LrSdkVersion = 8.0,
    LrToolkitIdentifier = 'portal.reisinger.portal',
    LrPluginName = 'Reisinger Foto Portal',
    VERSION = { major = 1, minor = 1, revision = 1, build = 0, display = "1.1.1" },

    LrPluginInfoProvider = 'PluginInfoProvider.lua',

    LrExportMenuItems = {
        {
            title = "Bewertungs-Galerien verwalten...",
            file = "SelectionManager.lua",
        },
        {
            title = "Delivery-Galerien verwalten...",
            file = "DeliveryManager.lua",
        }
    }
}
