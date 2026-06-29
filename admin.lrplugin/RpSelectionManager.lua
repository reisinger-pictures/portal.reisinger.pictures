local LrPrefs = import 'LrPrefs'
local prefs = LrPrefs.prefsForPlugin()

local val = prefs.baseUrlRp
local baseUrl = (val and #val > 0) and val or "https://portal.reisinger.pictures"
local ManagerCore = require "ManagerCore"
ManagerCore("selection", baseUrl)
