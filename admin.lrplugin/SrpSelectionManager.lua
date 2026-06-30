local LrPrefs = import 'LrPrefs'
local prefs = LrPrefs.prefsForPlugin()

local val = prefs.baseUrlSrp
local baseUrl = (val and #val > 0) and val or "https://portal.story.reisinger.pictures"
local ManagerCore = require "ManagerCore"
ManagerCore("selection", baseUrl)
