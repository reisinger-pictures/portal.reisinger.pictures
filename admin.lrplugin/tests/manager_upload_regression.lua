-- Mock Lightroom SDK harness for ManagerCore's upload state machine.
-- It exercises the two CR-INF-004 regressions without requiring Lightroom:
-- cancellation must not reach the success prompt, and a failed rendition with
-- a pre-existing destination must not be uploaded.
package.path = "./?.lua;" .. package.path

local function readFile(path)
    local file = assert(io.open(path, "rb"))
    local content = file:read("*a")
    file:close()
    return content
end

local function loadManagerCore()
    local source = readFile("ManagerCore.lua")
    local imports = {
        { "local LrApplication = import 'LrApplication'", "local LrApplication = _G.LrApplication" },
        { "local LrView = import 'LrView'", "local LrView = _G.LrView" },
        { "local LrDialogs = import 'LrDialogs'", "local LrDialogs = _G.LrDialogs" },
        { "local LrTasks = import 'LrTasks'", "local LrTasks = _G.LrTasks" },
        { "local LrHttp = import 'LrHttp'", "local LrHttp = _G.LrHttp" },
        { "local LrBinding = import 'LrBinding'", "local LrBinding = _G.LrBinding" },
        { "local LrExportSession = import 'LrExportSession'", "local LrExportSession = _G.LrExportSession" },
        { "local LrProgressScope = import 'LrProgressScope'", "local LrProgressScope = _G.LrProgressScope" },
        { "local LrFileUtils = import 'LrFileUtils'", "local LrFileUtils = _G.LrFileUtils" },
        { "local LrPathUtils = import 'LrPathUtils'", "local LrPathUtils = _G.LrPathUtils" },
        { "local LrFunctionContext = import 'LrFunctionContext'", "local LrFunctionContext = _G.LrFunctionContext" },
        { "import 'LrColor'", "_G.LrColor" },
    }
    for _, replacement in ipairs(imports) do
        source = source:gsub(replacement[1], replacement[2], 1)
    end
    source = source:gsub("import 'LrPrefs'", "_G.LrPrefs")
    source = source:gsub("import 'LrColor'", "_G.LrColor")
    if loadstring then
        return assert(loadstring(source, "@ManagerCore.lua"))()
    end
    return assert(load(source, "@ManagerCore.lua"))()
end

local function viewFactory()
    return setmetatable({}, {
        __index = function()
            return function() return {} end
        end
    })
end

local function runScenario(scenario)
    local files = {}
    local directories = {}
    local messages = {}
    local confirms = 0
    local uploads = 0
    local progressChecks = 0
    local cancelAfter = scenario == "cancel" and 4 or math.huge
    local stalePath = "/tmp/regression-stale-rendition.jpg"

    local function addFile(path)
        files[path] = true
    end
    if scenario == "stale" then addFile(stalePath) end

    local function pathChild(parent, child)
        if parent:sub(-1) == "/" then return parent .. child end
        return parent .. "/" .. child
    end

    _G.LrPrefs = {
        prefsForPlugin = function()
            return { apiUser = "photographer@example.test", useLocal = false }
        end,
    }
    _G.LrApplication = {
        activeCatalog = function()
            return {
                getTargetPhotos = function()
                    return scenario == "cancel" and { {}, {} } or { {} }
                end,
            }
        end,
    }
    _G.LrView = {
        osFactory = viewFactory,
        bind = function() return {} end,
    }
    _G.LrDialogs = {
        presentModalDialog = function() return "ok" end,
        message = function(_, message)
            table.insert(messages, message or "")
        end,
        confirm = function()
            confirms = confirms + 1
            return "cancel"
        end,
    }
    _G.LrTasks = {
        startAsyncTask = function(callback) callback() end,
    }
    _G.LrHttp = { openUrlInBrowser = function(_) end }
    _G.LrBinding = {
        makePropertyTable = function()
            return { addObserver = function() end }
        end,
    }
    _G.LrProgressScope = setmetatable({}, {
        __call = function()
            return {
                setCancelable = function() end,
                isCanceled = function()
                    progressChecks = progressChecks + 1
                    return progressChecks >= cancelAfter
                end,
                setCaption = function() end,
                setPortionComplete = function() end,
                done = function() end,
            }
        end,
    })
    _G.LrFileUtils = {
        exists = function(path) return files[path] == true end,
        createAllDirectories = function(path)
            directories[path] = true
            return true
        end,
        delete = function(path)
            if files[path] then
                files[path] = nil
                return true, nil
            end
            if directories[path] then
                directories[path] = nil
                return true, nil
            end
            return false, "not found"
        end,
    }
    _G.LrPathUtils = {
        getStandardFilePath = function() return "/tmp/regression" end,
        child = pathChild,
        leafName = function(path) return path:match("([^/]+)$") or path end,
    }
    _G.LrFunctionContext = {
        callWithContext = function(_, callback) callback({}) end,
    }
    _G.LrColor = function() return {} end

    _G.LrExportSession = function()
        local index = 0
        local renditionCount = scenario == "cancel" and 2 or 1
        return {
            renditions = function()
                return function()
                    if index >= renditionCount then return nil end
                    index = index + 1
                    local path = scenario == "stale" and stalePath or ("/tmp/regression-rendition-" .. tostring(index) .. ".jpg")
                    return index, {
                        destinationPath = path,
                        photo = { getRawMetadata = function() return "uuid-" .. tostring(index) end },
                        waitForRender = function()
                            if scenario == "stale" then return false, "render skipped" end
                            addFile(path)
                            return true, path
                        end,
                    }
                end
            end,
        }
    end

    local oldApi = package.preload["Api"]
    local oldUtils = package.preload["Utils"]
    local oldMeta = package.preload["MetaGalleryDialog"]
    local oldGallery = package.preload["GalleryDialog"]
    local oldInvite = package.preload["InviteDialog"]
    local oldRating = package.preload["RatingStatusDialog"]
    local oldLoadedApi = package.loaded["Api"]
    local oldLoadedUtils = package.loaded["Utils"]
    local oldLoadedMeta = package.loaded["MetaGalleryDialog"]
    local oldLoadedGallery = package.loaded["GalleryDialog"]
    local oldLoadedInvite = package.loaded["InviteDialog"]
    local oldLoadedRating = package.loaded["RatingStatusDialog"]
    local oldOpen = io.open
    package.loaded["Api"] = nil
    package.loaded["Utils"] = nil
    package.loaded["MetaGalleryDialog"] = nil
    package.loaded["GalleryDialog"] = nil
    package.loaded["InviteDialog"] = nil
    package.loaded["RatingStatusDialog"] = nil

    package.preload["Api"] = function()
        return {
            setBaseUrl = function() end,
            getTitle = function(value) return value end,
            migrateLegacyPassword = function() end,
            getStoredPassword = function() return "password" end,
            storePassword = function() return true end,
            login = function() return "jwt", nil, nil end,
            checkRole = function() return true, {}, 200 end,
            createSession = function() return {} end,
            callWithSession = function(_, endpoint)
                if endpoint:find("galleries?filter_type=", 1, true) then
                    return { groups = {}, root_galleries = { { id = "gallery", name = "Galerie", type = "delivery", full_path = "gallery" } } }, 200
                end
                return {}, 200
            end,
            uploadWithSession = function()
                uploads = uploads + 1
                return "{}", 200, nil
            end,
        }
    end
    package.preload["Utils"] = function() return dofile("Utils.lua") end
    package.preload["MetaGalleryDialog"] = function() return function() end end
    package.preload["GalleryDialog"] = function() return function() end end
    package.preload["InviteDialog"] = function() return function() end end
    package.preload["RatingStatusDialog"] = function() return function() end end
    io.open = function(path, mode)
        if path:find("upload_log", 1, true) then
            return { write = function() end, close = function() end }
        end
        return oldOpen(path, mode)
    end

    local ManagerCore = loadManagerCore()
    ManagerCore("delivery", "https://portal.reisinger.pictures")

    io.open = oldOpen
    package.preload["Api"] = oldApi
    package.preload["Utils"] = oldUtils
    package.preload["MetaGalleryDialog"] = oldMeta
    package.preload["GalleryDialog"] = oldGallery
    package.preload["InviteDialog"] = oldInvite
    package.preload["RatingStatusDialog"] = oldRating
    package.loaded["Api"] = oldLoadedApi
    package.loaded["Utils"] = oldLoadedUtils
    package.loaded["MetaGalleryDialog"] = oldLoadedMeta
    package.loaded["GalleryDialog"] = oldLoadedGallery
    package.loaded["InviteDialog"] = oldLoadedInvite
    package.loaded["RatingStatusDialog"] = oldLoadedRating

    return uploads, confirms, messages
end

local cancelUploads, cancelConfirms, cancelMessages = runScenario("cancel")
assert(cancelUploads == 1, "cancellation scenario should upload only the first image")
assert(cancelConfirms == 0, "cancellation must not reach the success confirmation")
assert(table.concat(cancelMessages, "\n"):find("abgebrochen", 1, true), "cancellation message was not shown")

local staleUploads, staleConfirms = runScenario("stale")
assert(staleUploads == 0, "failed rendition with stale destination was uploaded")
assert(staleConfirms == 0, "stale-rendition scenario reported success")

local successUploads, successConfirms = runScenario("success")
assert(successUploads == 1, "successful rendition was not uploaded")
assert(successConfirms == 1, "successful upload did not reach the completion prompt")

print("Lua ManagerCore upload/state-machine mock regression checks passed (no live Lightroom runtime)")
