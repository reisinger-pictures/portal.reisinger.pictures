local LrApplication = import 'LrApplication'
local LrView = import 'LrView'
local LrDialogs = import 'LrDialogs'
local LrTasks = import 'LrTasks'
local LrHttp = import 'LrHttp'
local LrBinding = import 'LrBinding'
local LrExportSession = import 'LrExportSession'
local LrProgressScope = import 'LrProgressScope'
local LrFileUtils = import 'LrFileUtils'
local LrPathUtils = import 'LrPathUtils'
local LrFunctionContext = import 'LrFunctionContext'

local Api = require "Api"
local Utils = require "Utils"
local MetaGalleryDialog = require "MetaGalleryDialog"
local GalleryDialog = require "GalleryDialog"
local InviteDialog = require "InviteDialog"

return function(mode, baseUrl)
    LrTasks.startAsyncTask(function()
        Api.setBaseUrl(baseUrl)
        local brandName = string.find(baseUrl, "all%-the%.rest") and "ATR" or "B2B"
        local catalog = LrApplication.activeCatalog()
        local targetPhotos = catalog:getTargetPhotos()
        local photoCount = #targetPhotos

        local jwt = nil
        local loginFailed = false
        local lastErr = ""
        local lastDetail = ""
        
        -- 1. Login Loop
        while true do
            jwt, lastErr, lastDetail = Api.login()
            if jwt then
                local isAllowed, userData = Api.checkRole(jwt)
                if isAllowed then break else
                    LrDialogs.message(Api.getTitle("Zugriff verweigert"), "Dein Account hat nicht die erforderliche Fotografen- oder Admin-Rolle.", "critical")
                    return
                end
            else
                local success = false
                LrFunctionContext.callWithContext("LoginDialogContext", function(context)
                    local f = LrView.osFactory()
                    local prefs = import 'LrPrefs'.prefsForPlugin()
                    local props = LrBinding.makePropertyTable(context)
                    
                    props.email = prefs.apiUser or ""
                    props.password = prefs.apiPass or ""

                    local errUI = f:spacer { height = 0 }
                    if loginFailed then
                        local errText = "Fehler: " .. tostring(lastErr)
                        if lastDetail and lastDetail ~= "" then
                           errText = errText .. "\n\n" .. lastDetail
                        end
                        errUI = f:edit_field {
                            value = errText,
                            width_in_chars = 50,
                            height_in_lines = 5,
                            text_color = import 'LrColor'(0.8, 0, 0)
                        }
                    end

                    local contents = f:column {
                        spacing = f:control_spacing(),
                        f:static_text { 
                            title = loginFailed and ("Bitte Zugangsdaten für " .. brandName .. " Portal prüfen.") or ("Bitte für " .. brandName .. " Portal anmelden."), 
                            text_color = loginFailed and import 'LrColor'(0.8, 0, 0) or nil,
                            margin_bottom = 5 
                        },
                        errUI,
                        f:spacer { height = 5 },
                        f:row { f:static_text { title = "E-Mail:", width = 80 }, f:edit_field { value = LrView.bind{key="email", bind_to_object=props}, fill_horizontal = 1, width_in_chars = 30 } },
                        f:row { f:static_text { title = "Passwort:", width = 80 }, f:password_field { value = LrView.bind{key="password", bind_to_object=props}, fill_horizontal = 1, width_in_chars = 30 } }
                    }

                    local res = LrDialogs.presentModalDialog {
                        title = Api.getTitle("Login erforderlich (" .. brandName .. ")"),
                        contents = contents,
                        actionVerb = "Anmelden",
                        cancelVerb = "Abbrechen"
                    }

                    if res == "ok" then
                        prefs.apiUser = props.email
                        prefs.apiPass = props.password
                        loginFailed = true
                        success = true
                    end
                end)
                
                if not success then return end
            end
        end

        -- 2. Daten laden
        local treeData = nil
        local function reloadTree()
            local data, status = Api.call("/api/management/galleries?filter_type=" .. mode, "GET", nil, jwt)
            if status == 200 and data then treeData = data end
        end
        reloadTree()

        if not treeData then
            LrDialogs.message(Api.getTitle("Fehler"), "Galerien konnten nicht geladen werden.", "critical")
            return
        end

        -- 3. Haupt-UI
        LrFunctionContext.callWithContext("GalleryManagerContext", function(context)
            local props = LrBinding.makePropertyTable(context)
            local f = LrView.osFactory()
            
            local function updateDropdown()
                local flatGroups = Utils.flattenGroups(treeData.groups)
                if #flatGroups == 0 then table.insert(flatGroups, { title = "Keine Meta-Galerien", value = "" }) end
                props.groupItems = flatGroups
                
                local foundGroup = false
                for _, item in ipairs(flatGroups) do if item.value == props.selectedGroupId then foundGroup = true break end end
                if not foundGroup then props.selectedGroupId = flatGroups[1].value end
                props.hasGroup = (#flatGroups > 0 and flatGroups[1].value ~= "")

                local flatGalleries = Utils.flattenGalleries(treeData)
                props.galleries = flatGalleries
                
                local foundGal = false
                for _, item in ipairs(flatGalleries) do if item.value == props.selectedGalleryId then foundGal = true break end end
                if not foundGal then props.selectedGalleryId = flatGalleries[1].value end
                props.hasGallery = (#flatGalleries > 0 and flatGalleries[1].value ~= "")
            end

            props.groupItems = {}
            props.selectedGroupId = ""
            props.galleries = {}
            props.selectedGalleryId = ""
            props.canConvertToDelivery = false
            props.convertToDelivery = false

            updateDropdown()

            props:addObserver("selectedGalleryId", function()
                local selectedGal = nil
                for _, item in ipairs(props.galleries) do
                    if item.value == props.selectedGalleryId and item.raw then
                        selectedGal = item.raw; break
                    end
                end
                if selectedGal then
                    props.canConvertToDelivery = (selectedGal.is_live == true)
                    props.convertToDelivery = false
                else
                    props.canConvertToDelivery = false
                    props.convertToDelivery = false
                end
            end)

            local function handleReload()
                reloadTree()
                updateDropdown()
            end

            local uiElements = { spacing = f:control_spacing(), width = 600 }
            table.insert(uiElements, f:static_text { title = string.format("Bereit für den Upload: %d Bilder", photoCount), font = "<system/bold>" })
            table.insert(uiElements, f:separator { fill_horizontal = 1 })
            
            -- Meta Galerien
            table.insert(uiElements, f:row {
                f:static_text { title = "Meta-Galerie (Ordner):", width = 130 },
                f:popup_menu { items = LrView.bind{key="groupItems", bind_to_object=props}, value = LrView.bind{key="selectedGroupId", bind_to_object=props}, fill_horizontal = 1 }
            })
            table.insert(uiElements, f:row {
                f:spacer { width = 130 },
                f:push_button { title = "+ Neu...", action = function() MetaGalleryDialog(nil, treeData, jwt, handleReload) end },
                f:push_button { 
                    title = "Bearbeiten...", 
                    enabled = LrView.bind{key="hasGroup", bind_to_object=props}, 
                    action = function() 
                        local selected = nil
                        for _, g in ipairs(props.groupItems) do if g.value == props.selectedGroupId then selected = g.raw break end end
                        if selected then MetaGalleryDialog(selected, treeData, jwt, handleReload) end
                    end 
                },
                f:push_button { 
                    title = "- Löschen...", enabled = LrView.bind{key="hasGroup", bind_to_object=props}, 
                    action = function()
                        local confirm = LrDialogs.confirm(Api.getTitle("Meta-Galerie löschen?"), "Alle Unterordner und Galerien werden in die Root-Ebene verschoben.", "Löschen", "Abbrechen")
                        if confirm == "ok" then
                            LrTasks.startAsyncTask(function()
                                local _, stat = Api.call("/api/management/gallery-groups/" .. props.selectedGroupId, "DELETE", nil, jwt)
                                if stat == 200 then handleReload() end
                            end)
                        end
                    end
                }
            })

            table.insert(uiElements, f:spacer { height = 10 })
            table.insert(uiElements, f:separator { fill_horizontal = 1 })
            table.insert(uiElements, f:spacer { height = 10 })

            -- Ziel Galerien
            table.insert(uiElements, f:row {
                f:static_text { title = "Ziel-Galerie:", width = 130 },
                f:popup_menu { items = LrView.bind{key="galleries", bind_to_object=props}, value = LrView.bind{key="selectedGalleryId", bind_to_object=props}, fill_horizontal = 1 }
            })
            
            table.insert(uiElements, f:row {
                f:spacer { width = 130 },
                f:push_button { title = "+ Neu...", action = function() GalleryDialog(mode, nil, treeData, jwt, handleReload) end },
                f:push_button { 
                    title = "Bearbeiten...", 
                    enabled = LrView.bind{key="hasGallery", bind_to_object=props}, 
                    action = function() 
                        local selected = nil
                        for _, g in ipairs(props.galleries) do if g.value == props.selectedGalleryId then selected = g.raw break end end
                        if selected then GalleryDialog(mode, selected, treeData, jwt, handleReload) end
                    end 
                },
                f:push_button { title = "Einladungs-Links...", enabled = LrView.bind{key="hasGallery", bind_to_object=props}, action = function() InviteDialog(props.selectedGalleryId, jwt) end },
                f:push_button { 
                    title = "- Löschen...", enabled = LrView.bind{key="hasGallery", bind_to_object=props}, 
                    action = function()
                        local confirm = LrDialogs.confirm(Api.getTitle("Galerie löschen?"), "Bilder, Personen und Bewertungen werden unwiderruflich gelöscht.", "Löschen", "Abbrechen")
                        if confirm == "ok" then
                            LrTasks.startAsyncTask(function()
                                local _, stat = Api.call("/api/management/galleries/" .. props.selectedGalleryId, "DELETE", nil, jwt)
                                if stat == 200 then handleReload() end
                            end)
                        end
                    end
                }
            })

            if mode == "delivery" then
                table.insert(uiElements, f:row {
                    f:spacer { width = 130 },
                    f:checkbox { title = "Live-Modus nach Upload beenden (Finale Bilder ausliefern)", value = LrView.bind{key="convertToDelivery", bind_to_object=props}, enabled = LrView.bind{key="canConvertToDelivery", bind_to_object=props} }
                })
            end

            if mode == "selection" then
                table.insert(uiElements, f:spacer { height = 5 })
                table.insert(uiElements, f:row {
                    f:spacer { width = 130 },
                    f:push_button {
                        title = "Bewertungen synchronisieren...",
                        enabled = LrView.bind{key="hasGallery", bind_to_object=props},
                        action = function()
                            LrTasks.startAsyncTask(function()
                                local resData, stat = Api.call("/api/management/galleries/" .. props.selectedGalleryId .. "/export", "GET", nil, jwt)
                                if stat == 200 and resData then
                                    catalog:withWriteAccessDo("Bewertungen synchronisieren", function()
                                        local matchCount = 0
                                        for _, item in ipairs(resData) do
                                            if item.lr_uuid then
                                                local photo = catalog:findPhotoByUuid(item.lr_uuid)
                                                if photo then
                                                    matchCount = matchCount + 1
                                                    if item.avg_rating then photo:setRawMetadata("rating", tonumber(item.avg_rating)) end
                                                    if item.all_comments and item.all_comments ~= "" then photo:setRawMetadata("instructions", item.all_comments) end
                                                end
                                            end
                                        end
                                        LrDialogs.message(Api.getTitle("Synchronisation abgeschlossen"), matchCount .. " Bilder wurden aktualisiert.", "info")
                                    end)
                                end
                            end)
                        end
                    }
                })
            end

            local result = LrDialogs.presentModalDialog {
                title = Api.getTitle((mode == "selection" and "Bewertungs-Galerie Manager" or "Delivery-Galerie Manager") .. " (" .. brandName .. ")"),
                resizable = true,
                contents = f:column(uiElements),
                actionVerb = "Upload starten",
                cancelVerb = "Schließen"
            }

            -- 4. Upload Execution
            if result == "ok" then
                if not props.hasGallery or props.selectedGalleryId == "" then LrDialogs.message(Api.getTitle("Abbruch"), "Bitte eine Galerie auswählen.", "warning"); return end
                if photoCount == 0 then LrDialogs.message(Api.getTitle("Keine Bilder"), "Bitte markiere Bilder im Lightroom Raster.", "warning"); return end

                LrTasks.startAsyncTask(function()
                    local tempPath = LrPathUtils.getStandardFilePath('temp')
                    local galleryUploadDir = LrPathUtils.child(tempPath, "Reisinger_Uploads_" .. props.selectedGalleryId)
                    LrFileUtils.createAllDirectories(galleryUploadDir)
                    
                    local logFilePath = LrPathUtils.child(galleryUploadDir, "upload_log.txt")
                    local function logMsg(msg)
                        local fl = io.open(logFilePath, "a")
                        if fl then
                            fl:write(tostring(os.date()) .. " - " .. tostring(msg) .. "\n")
                            fl:close()
                        end
                    end

                    logMsg("=== NEUER EXPORT/UPLOAD GESTARTET ===")
                    logMsg("Galerie ID: " .. tostring(props.selectedGalleryId))
                    logMsg("Anzahl Bilder: " .. tostring(photoCount))

                    if mode == "delivery" and props.convertToDelivery then Api.call("/api/management/galleries/" .. props.selectedGalleryId, "PUT", { is_live = false }, jwt) end

                    local progress = LrProgressScope({ title = Api.getTitle("Exportiere und Lade hoch (" .. photoCount .. " Bilder)...") })
                    progress:setCancelable(true)

                    local exportSettings = { LR_format = "JPEG", LR_export_quality = 80, LR_export_colorSpace = "sRGB", LR_export_destinationType = "specificFolder", LR_export_destinationPathPrefix = galleryUploadDir, LR_export_useSubfolder = false }
                    if mode == "selection" then
                        exportSettings.LR_size_doConstrain = true; exportSettings.LR_size_doNotEnlarge = true; exportSettings.LR_size_resizeType = "longEdge"; exportSettings.LR_size_maxWidth = 3000; exportSettings.LR_size_maxHeight = 3000; exportSettings.LR_minimizeEmbeddedMetadata = false; exportSettings.LR_removeLocationMetadata = false
                    else
                        exportSettings.LR_size_doConstrain = false; exportSettings.LR_minimizeEmbeddedMetadata = false; exportSettings.LR_removeLocationMetadata = false
                    end

                    exportSettings.LR_collisionHandling = "skip"

                    local session = LrExportSession({ photosToExport = targetPhotos, exportSettings = exportSettings })
                    local i = 0
                    local errorCount = 0
                    
                    for _, rendition in session:renditions() do
                        if progress:isCanceled() then 
                            logMsg("Upload durch Benutzer abgebrochen.")
                            break 
                        end
                        i = i + 1
                        
                        local path = rendition.destinationPath
                        local success = false
                        
                        logMsg("--- Bild " .. i .. " ---")
                        if path then logMsg("Geplanter Zielpfad: " .. path) end

                        logMsg("Warte auf Lightroom-Render...")
                        local ok, pathOrMessage = rendition:waitForRender()
                        if ok then
                            path = pathOrMessage
                            success = true
                            logMsg("Render erfolgreich: " .. tostring(path))
                        else
                            -- Lightroom hat wg. 'skip' nicht gerendert oder es gab einen Fehler
                            if path and LrFileUtils.exists(path) then
                                logMsg("Render durch LR übersprungen (Datei existiert), nutze existierende Datei: " .. tostring(path))
                                success = true
                            else
                                logMsg("Render fehlgeschlagen oder Datei fehlt: " .. tostring(pathOrMessage))
                            end
                        end

                        if success and path then
                            local filename = LrPathUtils.leafName(path)
                            local lrUuid = rendition.photo:getRawMetadata("uuid")
                            progress:setCaption("Upload " .. i .. "/" .. photoCount .. ": " .. filename)

                            logMsg("Starte Upload für UUID: " .. tostring(lrUuid) .. " (Backend generiert nun UUID-Filenames)")
                            
                            local resBody, resHeaders = LrHttp.postMultipart(Api.baseUrl .. "/api/management/upload", {
                                { name = "gallery_id", value = tostring(props.selectedGalleryId) },
                                { name = "lr_uuid",    value = lrUuid },
                                { name = "file",       fileName = filename, filePath = path, contentType = "image/jpeg" }
                            }, { { field = "Authorization", value = "Bearer " .. jwt } })

                            local status = resHeaders and resHeaders.status
                            logMsg("HTTP Status: " .. tostring(status))

                            if status == 200 then 
                                logMsg("Upload erfolgreich. Lösche lokale Datei.")
                                LrFileUtils.delete(path) 
                            else 
                                errorCount = errorCount + 1
                                local errDetail = resBody or (resHeaders and resHeaders.error and resHeaders.error.localizedMessage) or "Unbekannter Fehler"
                                logMsg("UPLOAD FEHLER: " .. tostring(errDetail))
                                LrFunctionContext.callWithContext("UploadError", function(cx)
                                    local viewFactory = LrView.osFactory()
                                    LrDialogs.presentModalDialog {
                                        title = Api.getTitle("Upload Fehler"),
                                        contents = viewFactory:column {
                                            spacing = viewFactory:control_spacing(),
                                            viewFactory:static_text { title = "Bild " .. filename .. " fehlgeschlagen (HTTP " .. tostring(status or 'N/A') .. ")." },
                                            viewFactory:edit_field { value = errDetail, height_in_lines = 10, width_in_chars = 50 }
                                        },
                                        cancelVerb = "< exclude >",
                                        actionVerb = "OK"
                                    }
                                end)
                            end
                        else
                            logMsg("Überspringe Upload, success=false oder path=nil")
                            errorCount = errorCount + 1
                        end
                        progress:setPortionComplete(i, photoCount)
                    end
                    progress:done()
                    
                    local selectedGalPath = ""
                    for _, g in ipairs(props.galleries) do
                        if g.value == props.selectedGalleryId and g.raw then
                            selectedGalPath = g.raw.full_path
                            break
                        end
                    end

                    if errorCount == 0 then
                        local confirm = LrDialogs.confirm(
                            Api.getTitle("Upload abgeschlossen!"), 
                            "Alle Bilder (" .. photoCount .. ") wurden erfolgreich hochgeladen.\n\nMöchtest du die Galerie jetzt im Web-Portal öffnen, um sie zu überprüfen und Kunden zu benachrichtigen?", 
                            "Im Web öffnen", 
                            "Schließen"
                        )
                        if confirm == "ok" and selectedGalPath ~= "" then
                            local url = Api.baseUrl .. "/" .. selectedGalPath
                            LrHttp.openUrlInBrowser(url)
                        end
                    else
                        LrDialogs.message(
                            Api.getTitle("Upload mit Fehlern abgeschlossen"), 
                            errorCount .. " von " .. photoCount .. " Bildern konnten nicht hochgeladen werden.\n\nBitte prüfe die aufgetretenen Fehlermeldungen oder die Log-Datei im Temp-Ordner.", 
                            "warning"
                        )
                    end
                    logMsg("=== UPLOAD SCHLEIFE BEENDET ===")
                end)
            end
        end)
    end)
end
