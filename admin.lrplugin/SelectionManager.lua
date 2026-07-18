local LrPrefs = import 'LrPrefs'
local prefs = LrPrefs.prefsForPlugin()

local baseUrl = prefs.useLocal and "http://localhost:4321" or "https://portal.reisinger.pictures"
local ManagerCore = require "ManagerCore"
ManagerCore("selection", baseUrl)
