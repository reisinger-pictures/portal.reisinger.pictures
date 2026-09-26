local LrView = import 'LrView'
local LrDialogs = import 'LrDialogs'
local LrBinding = import 'LrBinding'
local LrFunctionContext = import 'LrFunctionContext'
local LrTasks = import 'LrTasks'
local LrApplication = import 'LrApplication'
local Api = require "Api"

return function(galleryId, galleryName, jwt, onSyncComplete, requestApi)
    LrFunctionContext.callWithContext("RatingStatusContext", function(context)
        local f = LrView.osFactory()
        local props = LrBinding.makePropertyTable(context)
        local function apiRequest(endpoint, method, payload)
            if requestApi then return requestApi(endpoint, method, payload) end
            local data, status, _, _, errorDetail = Api.call(endpoint, method, payload, jwt)
            return data, status, errorDetail
        end

        props.loading = true
        props.loaded = false
        props.error = false
        props.contentVisible = false
        props.errorText = "Fehler beim Laden der Bewertungen."
        props.users = {}
        props.totalPhotos = 0
        props.ratings = {}
        props.syncEnabled = false
        props.syncPick = true
        props.syncRating = true
        props.syncComments = true
        props.usersText = ""
        props.ratingsText = ""

        local function buildUserLines(users, totalPhotos)
            local lines = {}
            for _, u in ipairs(users) do
                local name = u.name or "Unbekannt"
                local email = ""
                if u.email and not string.find(u.email, "@invite.local") then email = u.email end
                local progress = tostring(u.rated_count) .. "/" .. tostring(totalPhotos) .. " bewertet"
                table.insert(lines, name .. (email ~= "" and (" (" .. email .. ")") or "") .. " — " .. progress)
            end
            return table.concat(lines, "\n")
        end

        local function buildRatingLines(ratings)
            local lines = {}
            for _, r in ipairs(ratings) do
                local stars = r.avg_rating and r.avg_rating > 0 and (string.rep("★", r.avg_rating) .. string.rep("☆", 5 - r.avg_rating)) or "—"
                local comments = (r.all_comments and r.all_comments ~= "") and r.all_comments or "—"
                table.insert(lines, r.filename .. " | " .. stars .. " | " .. comments)
            end
            return table.concat(lines, "\n\n")
        end

        -- Runs inside an async task: LrHttp must never be called while the
        -- dialog is being constructed, or Lightroom freezes.
        local function loadData()
            local dataExport, statusExport, detailExport = apiRequest("/api/management/galleries/" .. galleryId .. "/export", "GET", nil)
            if statusExport ~= 200 or type(dataExport) ~= "table" then
                props.errorText = "Fehler beim Laden der Bewertungen (HTTP " .. tostring(statusExport) .. ")."
                if detailExport and detailExport ~= "" then props.errorText = props.errorText .. "\n" .. tostring(detailExport) end
                props.error = true
                props.contentVisible = false
                props.loading = false
                props.loaded = true
                return
            end

            local dataStatus, statusStatus, detailStatus = apiRequest("/api/management/galleries/" .. galleryId .. "/rating-status", "GET", nil)
            if statusStatus ~= 200 or type(dataStatus) ~= "table" then
                props.errorText = "Fehler beim Laden der Bewertungsübersicht (HTTP " .. tostring(statusStatus) .. ")."
                if detailStatus and detailStatus ~= "" then props.errorText = props.errorText .. "\n" .. tostring(detailStatus) end
                props.error = true
                props.contentVisible = false
                props.loading = false
                props.loaded = true
                return
            end

            props.ratings = dataExport
            props.users = type(dataStatus.users) == "table" and dataStatus.users or {}
            props.totalPhotos = tonumber(dataStatus.total_photos) or 0
            props.syncEnabled = (#props.ratings > 0)
            props.usersText = (#props.users > 0) and buildUserLines(props.users, props.totalPhotos) or "Keine Personen mit Bewertungen."
            props.ratingsText = (#props.ratings > 0) and buildRatingLines(props.ratings) or "Noch keine Bewertungen vorhanden."
            props.error = false
            props.contentVisible = true
            props.loading = false
            props.loaded = true
        end

        local function runSync()
            local catalog = LrApplication.activeCatalog()
            local resData, stat, detail = apiRequest("/api/management/galleries/" .. galleryId .. "/export", "GET", nil)
            if stat ~= 200 or type(resData) ~= "table" then
                local message = "Bewertungen konnten nicht geladen werden (HTTP " .. tostring(stat) .. ")."
                if detail and detail ~= "" then message = message .. "\n" .. tostring(detail) end
                LrDialogs.message(Api.getTitle("Fehler"), message, "critical")
                return
            end

            catalog:withWriteAccessDo("Bewertungen synchronisieren", function()
                local matchCount = 0
                for _, item in ipairs(resData) do
                    if item.lr_uuid then
                        local photo = catalog:findPhotoByUuid(item.lr_uuid)
                        if photo then
                            matchCount = matchCount + 1
                            if props.syncRating and item.avg_rating then
                                local rating = tonumber(item.avg_rating)
                                photo:setRawMetadata("rating", rating)
                                if props.syncPick then
                                    if rating >= 4 then
                                        photo:setRawMetadata("pick", 1)
                                    else
                                        photo:setRawMetadata("pick", 0)
                                    end
                                end
                            end
                            if props.syncComments and item.all_comments and item.all_comments ~= "" then
                                photo:setRawMetadata("instructions", item.all_comments)
                            end
                        end
                    end
                end
                LrDialogs.message(Api.getTitle("Synchronisation abgeschlossen"), matchCount .. " Bilder wurden aktualisiert.", "info")
                if onSyncComplete then onSyncComplete() end
            end)
        end

        local rows = { spacing = f:control_spacing(), width = 700 }

        table.insert(rows, f:static_text {
            title = "Lade Bewertungen...",
            visible = LrView.bind{ key = "loading", bind_to_object = props }
        })
        table.insert(rows, f:static_text {
            title = LrView.bind{ key = "errorText", bind_to_object = props },
            text_color = import 'LrColor'(0.8, 0, 0),
            visible = LrView.bind{ key = "error", bind_to_object = props }
        })

        local content = f:column {
            spacing = f:control_spacing(),
            f:static_text { title = "Beteiligte Personen", font = "<system/bold>" },
            f:spacer { height = 5 },
            f:edit_field {
                value = LrView.bind{ key = "usersText", bind_to_object = props },
                height_in_lines = 4,
                width_in_chars = 60,
                readonly = true
            },
            f:spacer { height = 15 },
            f:separator { fill_horizontal = 1 },
            f:spacer { height = 5 },
            f:static_text { title = "Detaillierte Auswertungen (Bild-Bewertungen)", font = "<system/bold>" },
            f:spacer { height = 5 },
            f:edit_field {
                value = LrView.bind{ key = "ratingsText", bind_to_object = props },
                height_in_lines = 12,
                width_in_chars = 80,
                readonly = true
            },
            f:spacer { height = 15 },
            f:separator { fill_horizontal = 1 },
            f:spacer { height = 5 },
            f:static_text { title = "Synchronisations-Optionen", font = "<system/bold>" },
            f:checkbox { title = "Ø Sterne in LR-Rating übernehmen", value = LrView.bind{key="syncRating", bind_to_object=props}, enabled = LrView.bind{key="syncEnabled", bind_to_object=props} },
            f:checkbox { title = "Pick-Flag bei Ø ≥ 4 Sterne setzen", value = LrView.bind{key="syncPick", bind_to_object=props}, enabled = LrView.bind{key="syncEnabled", bind_to_object=props} },
            f:checkbox { title = "Kommentare in LR-Instructions schreiben", value = LrView.bind{key="syncComments", bind_to_object=props}, enabled = LrView.bind{key="syncEnabled", bind_to_object=props} }
        }

        table.insert(rows, f:row {
            visible = LrView.bind{ key = "contentVisible", bind_to_object = props },
            content
        })

        LrTasks.startAsyncTask(function()
            loadData()
        end)

        local result = LrDialogs.presentModalDialog {
            title = Api.getTitle("Bewertungen & Status — " .. galleryName),
            contents = f:column(rows),
            actionVerb = "Sync to Lightroom",
            cancelVerb = "Schließen"
        }

        if result == "ok" and props.syncEnabled then
            LrTasks.startAsyncTask(function()
                runSync()
            end)
        end
    end)
end
