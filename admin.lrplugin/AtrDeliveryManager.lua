local LrPrefs = import 'LrPrefs'
local prefs = LrPrefs.prefsForPlugin()

local val = prefs.baseUrlAtr
local baseUrl = (val and #val > 0) and val or "https://portal.all-the.rest"
local ManagerCore = require "ManagerCore"
ManagerCore("delivery", baseUrl)
