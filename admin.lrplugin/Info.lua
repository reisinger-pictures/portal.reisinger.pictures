return {
    LrSdkVersion = 8.0,
    LrToolkitIdentifier = 'portal.reisinger.portal',
    LrPluginName = 'Reisinger Foto Portal',
    VERSION = { major = 1, minor = 1, revision = 0, build = 0, display = "1.1.0" },

    LrPluginInfoProvider = 'PluginInfoProvider.lua',

    LrExportMenuItems = {
        {
            title = "Bewertungs-Galerien verwalten (B2B)...",
            file = "RpSelectionManager.lua",
        },
        {
            title = "Delivery-Galerien verwalten (B2B)...",
            file = "RpDeliveryManager.lua",
        },
        {
            title = "Bewertungs-Galerien verwalten (ATR)...",
            file = "AtrSelectionManager.lua",
        },
        {
            title = "Delivery-Galerien verwalten (ATR)...",
            file = "AtrDeliveryManager.lua",
        }
    }
}
