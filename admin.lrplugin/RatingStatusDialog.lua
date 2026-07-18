local LrView = import 'LrView'
local LrDialogs = import 'LrDialogs'
local LrBinding = import 'LrBinding'
local LrFunctionContext = import 'LrFunctionContext'
local LrTasks = import 'LrTasks'
local LrApplication = import 'LrApplication'
local Api = require "Api"

return function(galleryId, galleryName, jwt, onSyncComplete)
    LrFunctionContext.callWithContext("RatingStatusContext", function(context)
        local f = LrView.osFactory()
        local props = LrBinding.makePropertyTable(context)

        props.error = false
        props.users = {}
        props.totalPhotos = 0
        props.ratings = {}
        props.syncEnabled = false
        props.syncPick = true
        props.syncRating = true
        props.syncComments = true

        local function loadData()
            props.error = false

            local dataExport, statusExport = Api.call("/api/management/galleries/" .. galleryId .. "/export", "GET", nil, jwt)
            local dataStatus, statusStatus = Api.call("/api/management/galleries/" .. galleryId .. "/rating-status", "GET", nil, jwt)

            if statusExport == 200 and statusStatus == 200 and dataExport and dataStatus then
                props.ratings = dataExport
                props.users = dataStatus.users or {}
                props.totalPhotos = dataStatus.total_photos or 0
                props.syncEnabled = (#props.ratings > 0)
            else
                props.error = true
            end
        end

        loadData()

        local function runSync()
            local catalog = LrApplication.activeCatalog()
            local resData, stat = Api.call("/api/management/galleries/" .. galleryId .. "/export", "GET", nil, jwt)
            if stat ~= 200 or not resData then
                LrDialogs.message(Api.getTitle("Fehler"), "Bewertungen konnten nicht geladen werden.", "critical")
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

        if props.error then
            table.insert(rows, f:static_text { title = "Fehler beim Laden der Bewertungen.", text_color = LrView.color(0.8, 0, 0) })
        else
            table.insert(rows, f:static_text { title = "Beteiligte Personen", font = "<system/bold>" })
            table.insert(rows, f:spacer { height = 5 })

            if #props.users > 0 then
                local userLines = {}
                for _, u in ipairs(props.users) do
                    local name = u.name or "Unbekannt"
                    local email = ""
                    if u.email and not string.find(u.email, "@invite.local") then email = u.email end
                    local progress = u.rated_count .. "/" .. props.totalPhotos .. " bewertet"
                    table.insert(userLines, name .. (email ~= "" and (" (" .. email .. ")") or "") .. " — " .. progress)
                end
                table.insert(rows, f:edit_field {
                    value = table.concat(userLines, "\n"),
                    height_in_lines = math.min(#userLines, 8),
                    width_in_chars = 60,
                    readonly = true
                })
            else
                table.insert(rows, f:static_text { title = "Keine Personen mit Bewertungen.", text_color = LrView.color(0.5, 0.5, 0.5) })
            end

            table.insert(rows, f:spacer { height = 15 })
            table.insert(rows, f:separator { fill_horizontal = 1 })
            table.insert(rows, f:spacer { height = 5 })

            table.insert(rows, f:static_text { title = "Detaillierte Auswertungen (Bild-Bewertungen)", font = "<system/bold>" })
            table.insert(rows, f:spacer { height = 5 })

            if #props.ratings > 0 then
                local ratingLines = {}
                for _, r in ipairs(props.ratings) do
                    local stars = r.avg_rating and r.avg_rating > 0 and (string.rep("★", r.avg_rating) .. string.rep("☆", 5 - r.avg_rating)) or "—"
                    local comments = (r.all_comments and r.all_comments ~= "") and r.all_comments or "—"
                    table.insert(ratingLines, r.filename .. " | " .. stars .. " | " .. comments)
                end
                table.insert(rows, f:edit_field {
                    value = table.concat(ratingLines, "\n\n"),
                    height_in_lines = math.min(#ratingLines * 3, 15),
                    width_in_chars = 80,
                    readonly = true
                })
            else
                table.insert(rows, f:static_text { title = "Noch keine Bewertungen vorhanden.", text_color = LrView.color(0.5, 0.5, 0.5) })
            end

            table.insert(rows, f:spacer { height = 15 })
            table.insert(rows, f:separator { fill_horizontal = 1 })
            table.insert(rows, f:spacer { height = 5 })

            table.insert(rows, f:static_text { title = "Synchronisations-Optionen", font = "<system/bold>" })
            table.insert(rows, f:checkbox { title = "Ø Sterne in LR-Rating übernehmen", value = LrView.bind{key="syncRating", bind_to_object=props}, enabled = LrView.bind{key="syncEnabled", bind_to_object=props} })
            table.insert(rows, f:checkbox { title = "Pick-Flag bei Ø ≥ 4 Sterne setzen", value = LrView.bind{key="syncPick", bind_to_object=props}, enabled = LrView.bind{key="syncEnabled", bind_to_object=props} })
            table.insert(rows, f:checkbox { title = "Kommentare in LR-Instructions schreiben", value = LrView.bind{key="syncComments", bind_to_object=props}, enabled = LrView.bind{key="syncEnabled", bind_to_object=props} })
        end

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
