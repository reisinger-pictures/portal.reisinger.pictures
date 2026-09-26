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
local RatingStatusDialog = require "RatingStatusDialog"

-- A fresh directory per invocation prevents a previous export from colliding
-- with this run's renditions.  The counter also separates invocations within
-- the same Lightroom process/second.
local uploadRunCounter = 0

local function makeUploadDirectory(tempPath, galleryId)
    uploadRunCounter = uploadRunCounter + 1
    local root = LrPathUtils.child(tempPath, "Reisinger_Uploads_" .. tostring(galleryId))
    local runSuffix = tostring(os.time()) .. "-" .. tostring(uploadRunCounter)
    local candidate = LrPathUtils.child(root, runSuffix)

    -- A Lightroom restart resets the in-process counter.  Never reuse a
    -- directory that survived such a restart (or an interrupted export).
    local collision = 0
    while LrFileUtils.exists(candidate) do
        collision = collision + 1
        candidate = LrPathUtils.child(root, runSuffix .. "-" .. tostring(collision))
    end
    return candidate
end

return function(mode, baseUrl)
    LrTasks.startAsyncTask(function()
        Api.setBaseUrl(baseUrl)
        local catalog = LrApplication.activeCatalog()
        local targetPhotos = catalog:getTargetPhotos()
        local photoCount = #targetPhotos

        local jwt = nil
        local session = nil
        local prefs = import 'LrPrefs'.prefsForPlugin()
        -- Move any plaintext password from an older plugin version into the
        -- OS-protected credential store, then try a silent login with the
        -- saved credentials. If the keychain is unavailable, keep the legacy
        -- value until it can be migrated rather than silently deleting it.
        -- INFRA-12: the migration is retried after every successful login, but
        -- the warning is surfaced at most once per manager run.
        local legacyMigrationWarningShown = false
        local function warnLegacyMigrationFailure()
            if legacyMigrationWarningShown then return end
            legacyMigrationWarningShown = true
            LrDialogs.message(
                Api.getTitle("Passwortmigration"),
                "Das gespeicherte Passwort konnte nicht in den Betriebssystem-Schlüsselbund übernommen werden. Bitte melde dich erneut an.",
                "warning"
            )
        end

        if Api.migrateLegacyPassword() == false then
            warnLegacyMigrationFailure()
        end
        local credEmail = prefs.apiUser or ""
        local credPassword = Api.getStoredPassword()

        local loginFailed = false
        local lastErr = ""
        local lastDetail = ""
        
        -- 1. Login Loop
        while true do
            if not credPassword then
                local submitted = false
                LrFunctionContext.callWithContext("LoginDialogContext", function(context)
                    local f = LrView.osFactory()
                    local props = LrBinding.makePropertyTable(context)
                    
                    props.email = credEmail
                    props.password = ""

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
                            title = loginFailed and ("Bitte Zugangsdaten für das Reisinger Foto Portal prüfen.") or ("Bitte für das Reisinger Foto Portal anmelden."),
                            text_color = loginFailed and import 'LrColor'(0.8, 0, 0) or nil,
                            margin_bottom = 5 
                        },
                        errUI,
                        f:spacer { height = 5 },
                        f:row { f:static_text { title = "E-Mail:", width = 80 }, f:edit_field { value = LrView.bind{key="email", bind_to_object=props}, fill_horizontal = 1, width_in_chars = 30 } },
                        f:row { f:static_text { title = "Passwort:", width = 80 }, f:password_field { value = LrView.bind{key="password", bind_to_object=props}, fill_horizontal = 1, width_in_chars = 30 } }
                    }

                    local res = LrDialogs.presentModalDialog {
                        title = Api.getTitle("Login erforderlich (Reisinger Foto Portal)"),
                        contents = contents,
                        actionVerb = "Anmelden",
                        cancelVerb = "Abbrechen"
                    }

                    if res == "ok" then
                        credEmail = props.email or ""
                        credPassword = props.password or ""
                        submitted = true
                    end
                end)
                
                if not submitted then return end
                -- Only the e-mail goes into LrPrefs; the password is stored
                -- separately in the OS-protected credential store (on success, below).
                prefs.apiUser = credEmail
                loginFailed = true
            end

            jwt, lastErr, lastDetail = Api.login(credEmail, credPassword)
            if jwt then
                local passwordStored = true
                if credPassword and credPassword ~= "" then
                    passwordStored = Api.storePassword(credPassword)
                end
                -- Do not keep a plaintext password in the long-lived manager
                -- closure; renewal must use the protected Api.login fallback.
                credPassword = nil
                if not passwordStored then
                    LrDialogs.message(
                        Api.getTitle("Passwort nicht gespeichert"),
                        "Die Anmeldung war erfolgreich, das Passwort konnte aber nicht im Betriebssystem-Schlüsselbund gespeichert werden. Beim nächsten Start muss es erneut eingegeben werden.",
                        "warning"
                    )
                end
                -- INFRA-12: a keychain that was unavailable at startup may have
                -- recovered. Retry the legacy plaintext migration now that the
                -- login succeeded; the warning is shown at most once per run.
                if Api.migrateLegacyPassword() == false then
                    warnLegacyMigrationFailure()
                end
                if not session then
                    session = Api.createSession(jwt, credEmail, function()
                        return Api.login(credEmail)
                    end)
                end
                local isAllowed, userData, roleStatus, roleDetail = Api.checkRole(session)
                if isAllowed then
                    break
                elseif roleStatus == 401 then
                    LrDialogs.message(
                        Api.getTitle("Sitzung abgelaufen"),
                        "Die Anmeldung konnte nicht erneuert werden. Bitte den Manager schließen und erneut starten.",
                        "critical"
                    )
                    return
                elseif roleStatus == 200 and userData then
                    LrDialogs.message(Api.getTitle("Zugriff verweigert"), "Dein Account hat nicht die erforderliche Fotografen- oder Admin-Rolle.", "critical")
                    return
                else
                    local message = "Die Rolle konnte nicht geprüft werden (HTTP " .. tostring(roleStatus) .. ")."
                    if roleDetail and roleDetail ~= "" then message = message .. "\n" .. tostring(roleDetail) end
                    LrDialogs.message(Api.getTitle("Verbindung fehlgeschlagen"), message .. " Bitte später erneut versuchen.", "critical")
                    return
                end
            else
                -- Wrong credentials or a network error: show the dialog again.
                loginFailed = true
                credPassword = nil
            end
        end

        -- The session was created after the successful login.  Keep only its
        -- mutable JWT; the protected-store fallback in Api.login is the only
        -- credential source used when a long manager session receives a 401.
        local sessionExpiredNotified = false
        local function requestApi(endpoint, method, payload)
            local data, status, _, _, errorDetail = Api.callWithSession(session, endpoint, method, payload)
            if status == 401 and not sessionExpiredNotified then
                sessionExpiredNotified = true
                LrDialogs.message(
                    Api.getTitle("Sitzung abgelaufen"),
                    "Deine Anmeldung konnte nicht automatisch erneuert werden. Bitte den Manager schließen, neu starten und erneut anmelden.",
                    "critical"
                )
            end
            return data, status, errorDetail
        end

        -- 2. Daten laden
        local treeData = nil
        local function reloadTree()
            local data, status, errorDetail = requestApi("/api/management/galleries?filter_type=" .. mode, "GET", nil)
            if status == 200 and data then
                treeData = data
                return true, status, nil
            end
            -- Keep the last successful tree intact so a failed refresh cannot
            -- silently turn the manager into an empty/stale-looking view.
            return false, status, errorDetail
        end

        local treeOk, treeStatus, treeDetail = reloadTree()
        if not treeOk or not treeData then
            local message = "Galerien konnten nicht geladen werden (HTTP " .. tostring(treeStatus) .. ")."
            if treeDetail and treeDetail ~= "" then message = message .. "\n" .. tostring(treeDetail) end
            LrDialogs.message(Api.getTitle("Fehler"), message, "critical")
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
                local ok, status, errorDetail = reloadTree()
                if not ok then
                    local message = "Galerien konnten nicht neu geladen werden (HTTP " .. tostring(status) .. ")."
                    if errorDetail and errorDetail ~= "" then message = message .. "\n" .. tostring(errorDetail) end
                    LrDialogs.message(Api.getTitle("Fehler"), message, "warning")
                    return
                end
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
                f:push_button { title = "+ Neu...", action = function() MetaGalleryDialog(nil, treeData, session, handleReload, requestApi) end },
                f:push_button { 
                    title = "Bearbeiten...", 
                    enabled = LrView.bind{key="hasGroup", bind_to_object=props}, 
                    action = function() 
                        local selected = nil
                        for _, g in ipairs(props.groupItems) do if g.value == props.selectedGroupId then selected = g.raw break end end
                        if selected then MetaGalleryDialog(selected, treeData, session, handleReload, requestApi) end
                    end 
                },
                f:push_button { 
                    title = "- Löschen...", enabled = LrView.bind{key="hasGroup", bind_to_object=props}, 
                    action = function()
                        local confirm = LrDialogs.confirm(Api.getTitle("Meta-Galerie löschen?"), "Alle Unterordner und Galerien werden in die Root-Ebene verschoben.", "Löschen", "Abbrechen")
                        if confirm == "ok" then
                            LrTasks.startAsyncTask(function()
                                local _, stat = requestApi("/api/management/gallery-groups/" .. props.selectedGroupId, "DELETE", nil)
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
                f:push_button { title = "+ Neu...", action = function() GalleryDialog(mode, nil, treeData, session, handleReload, requestApi) end },
                f:push_button { 
                    title = "Bearbeiten...", 
                    enabled = LrView.bind{key="hasGallery", bind_to_object=props}, 
                    action = function() 
                        local selected = nil
                        for _, g in ipairs(props.galleries) do if g.value == props.selectedGalleryId then selected = g.raw break end end
                        if selected then GalleryDialog(mode, selected, treeData, session, handleReload, requestApi) end
                    end 
                },
                f:push_button { title = "Einladungs-Links...", enabled = LrView.bind{key="hasGallery", bind_to_object=props}, action = function() InviteDialog(props.selectedGalleryId, session, requestApi) end },
                f:push_button { 
                    title = "- Löschen...", enabled = LrView.bind{key="hasGallery", bind_to_object=props}, 
                    action = function()
                        local confirm = LrDialogs.confirm(Api.getTitle("Galerie löschen?"), "Bilder, Personen und Bewertungen werden unwiderruflich gelöscht.", "Löschen", "Abbrechen")
                        if confirm == "ok" then
                            LrTasks.startAsyncTask(function()
                                local _, stat = requestApi("/api/management/galleries/" .. props.selectedGalleryId, "DELETE", nil)
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
                        title = "Bewertungen ansehen & synchronisieren...",
                        enabled = LrView.bind{key="hasGallery", bind_to_object=props},
                        action = function()
                            local selectedGal = nil
                            for _, g in ipairs(props.galleries) do
                                if g.value == props.selectedGalleryId and g.raw then
                                    selectedGal = g.raw
                                    break
                                end
                            end
                            local galName = selectedGal and selectedGal.name or "Galerie"
                            RatingStatusDialog(props.selectedGalleryId, galName, session, handleReload, requestApi)
                        end
                    }
                })
            end

            local result = LrDialogs.presentModalDialog {
                title = Api.getTitle((mode == "selection" and "Bewertungs-Galerie Manager" or "Delivery-Galerie Manager") .. " (Reisinger Foto Portal)"),
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
                    local galleryUploadDir = makeUploadDirectory(tempPath, props.selectedGalleryId)
                    local directoryCreated = LrFileUtils.createAllDirectories(galleryUploadDir)
                    if directoryCreated == false then
                        LrDialogs.message(Api.getTitle("Fehler"), "Das temporäre Upload-Verzeichnis konnte nicht erstellt werden. Upload abgebrochen.", "critical")
                        return
                    end
                    
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

                    if mode == "delivery" and props.convertToDelivery then
                        local _, convertStatus = requestApi("/api/management/galleries/" .. props.selectedGalleryId, "PUT", { is_live = false })
                        if convertStatus ~= 200 then
                            logMsg("Live-Modus konnte nicht beendet werden (HTTP " .. tostring(convertStatus) .. "). Upload abgebrochen.")
                            LrDialogs.message(Api.getTitle("Fehler"), "Der Live-Modus konnte nicht beendet werden. Upload abgebrochen.", "critical")
                            return
                        end
                    end

                    local progress = LrProgressScope({ title = Api.getTitle("Exportiere und Lade hoch (" .. photoCount .. " Bilder)...") })
                    if progress then progress:setCancelable(true) end

                    local exportSettings = { LR_format = "JPEG", LR_export_quality = 80, LR_export_colorSpace = "sRGB", LR_export_destinationType = "specificFolder", LR_export_destinationPathPrefix = galleryUploadDir, LR_export_useSubfolder = false }
                    if mode == "selection" then
                        exportSettings.LR_size_doConstrain = true; exportSettings.LR_size_doNotEnlarge = true; exportSettings.LR_size_resizeType = "longEdge"; exportSettings.LR_size_maxWidth = 3000; exportSettings.LR_size_maxHeight = 3000; exportSettings.LR_minimizeEmbeddedMetadata = false; exportSettings.LR_removeLocationMetadata = false
                    else
                        exportSettings.LR_size_doConstrain = false; exportSettings.LR_minimizeEmbeddedMetadata = false; exportSettings.LR_removeLocationMetadata = false
                    end

                    -- A fresh export directory plus overwrite semantics keeps
                    -- Lightroom from treating a previous run as a valid
                    -- collision.  Failed renders are never reused below.
                    exportSettings.LR_collisionHandling = "overwrite"

                    local exportSession = LrExportSession({ photosToExport = targetPhotos, exportSettings = exportSettings })
                    local i = 0
                    local errorCount = 0
                    local uploadedCount = 0
                    local sessionExpired = false
                    local cancelled = false
                    
                    for _, rendition in exportSession:renditions() do
                        if progress and progress:isCanceled() then
                            cancelled = true
                            logMsg("Upload durch Benutzer abgebrochen.")
                            break
                        end
                        i = i + 1

                        local path = rendition.destinationPath
                        local stalePath = false
                        if path then
                            stalePath = LrFileUtils.exists(path)
                            if stalePath then
                                -- A path surviving a previous run is never an
                                -- upload candidate.  Remove it before rendering;
                                -- if removal fails, fail closed below.
                                LrFileUtils.delete(path)
                                stalePath = LrFileUtils.exists(path)
                                if stalePath then
                                    logMsg("Vorherige Rendition konnte nicht entfernt werden; sie wird nicht verwendet.")
                                end
                            end
                        end

                        logMsg("--- Bild " .. i .. " ---")
                        if path then logMsg("Geplanter Zielpfad: " .. tostring(path)) end

                        logMsg("Warte auf Lightroom-Render...")
                        local renderOk, pathOrMessage = rendition:waitForRender()

                        -- A cancellation can arrive while Lightroom is rendering.
                        -- Never turn that cancelled wait into an upload.
                        if progress and progress:isCanceled() then
                            cancelled = true
                            logMsg("Render durch Benutzer abgebrochen; keine Datei wird verwendet.")
                            break
                        end

                        -- Only an explicit successful wait may produce an upload
                        -- candidate.  In particular, never fall back to an old
                        -- destinationPath when waitForRender failed.
                        local renderedPath = pathOrMessage
                        if renderedPath == nil or renderedPath == "" then renderedPath = path end
                        if renderOk ~= true or type(renderedPath) ~= "string" or renderedPath == ""
                            or stalePath or not LrFileUtils.exists(renderedPath) then
                            renderedPath = nil
                            logMsg("Render fehlgeschlagen oder abgebrochen; vorhandene Datei wird nicht verwendet: " .. tostring(pathOrMessage))
                        else
                            logMsg("Render erfolgreich: " .. tostring(renderedPath))
                        end

                        if renderedPath then
                            if progress and progress:isCanceled() then
                                cancelled = true
                                logMsg("Upload durch Benutzer abgebrochen.")
                                break
                            end
                            local filename = LrPathUtils.leafName(renderedPath)
                            local lrUuid = rendition.photo:getRawMetadata("uuid")
                            if progress then progress:setCaption("Upload " .. i .. "/" .. photoCount .. ": " .. filename) end

                            logMsg("Starte Upload für UUID: " .. tostring(lrUuid) .. " (Backend generiert nun UUID-Filenames)")

                            local formFields = {
                                { name = "gallery_id", value = tostring(props.selectedGalleryId) },
                                { name = "lr_uuid",    value = lrUuid },
                                { name = "replace",    value = "1" },
                                { name = "file",       fileName = filename, filePath = renderedPath, contentType = "image/jpeg" }
                            }

                            local _, status, uploadErr = Api.uploadWithSession(session, "/api/management/upload", formFields)
                            logMsg("HTTP Status: " .. tostring(status))

                            if status == 401 then
                                sessionExpired = true
                                logMsg("Sitzung abgelaufen (HTTP 401). Upload abgebrochen.")
                                break
                            elseif status >= 200 and status < 300 then
                                uploadedCount = uploadedCount + 1
                                -- Delete only a path that this run explicitly
                                -- rendered and successfully uploaded.  Failed
                                -- render candidates are retained for diagnosis.
                                LrFileUtils.delete(renderedPath)
                                if LrFileUtils.exists(renderedPath) then
                                    logMsg("Upload erfolgreich, lokale Datei konnte nicht gelöscht werden.")
                                else
                                    logMsg("Upload erfolgreich. Frische lokale Datei gelöscht.")
                                end
                                if progress and progress:isCanceled() then
                                    cancelled = true
                                    logMsg("Upload nach erfolgreicher Annahme abgebrochen.")
                                    break
                                end
                            else
                                if progress and progress:isCanceled() then
                                    cancelled = true
                                    logMsg("Upload nach Benutzerabbruch nicht erneut gestartet.")
                                    break
                                end
                                errorCount = errorCount + 1
                                local errDetail = uploadErr or ("HTTP " .. tostring(status))
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
                            logMsg("Überspringe Upload, da kein erfolgreich gerendertes Rendition-Ergebnis vorliegt.")
                            errorCount = errorCount + 1
                        end
                        if progress then progress:setPortionComplete(i, photoCount) end
                    end
                    if progress and progress:isCanceled() then cancelled = true end
                    if progress then progress:done() end

                    if sessionExpired then
                        logMsg("=== UPLOAD SCHLEIFE BEENDET (Sitzung abgelaufen) ===")
                        if not sessionExpiredNotified then
                            sessionExpiredNotified = true
                            LrDialogs.message(Api.getTitle("Sitzung abgelaufen"), "Deine Anmeldung ist abgelaufen. Bitte den Manager schließen, neu starten und erneut anmelden.", "critical")
                        end
                        return
                    end

                    if cancelled then
                        logMsg("Upload durch Benutzer abgebrochen. Hochgeladen: " .. tostring(uploadedCount) .. "/" .. tostring(photoCount))
                        LrDialogs.message(
                            Api.getTitle("Upload abgebrochen"),
                            "Der Upload wurde abgebrochen. " .. tostring(uploadedCount) .. " von " .. tostring(photoCount) .. " Bildern wurden bereits hochgeladen; lokale Renditionsdateien wurden nicht pauschal gelöscht.",
                            "warning"
                        )
                        return
                    end

                    local failedCount = math.max(0, math.min(photoCount, math.max(errorCount, photoCount - uploadedCount)))
                    local selectedGalPath = ""
                    for _, g in ipairs(props.galleries) do
                        if g.value == props.selectedGalleryId and g.raw then
                            selectedGalPath = g.raw.full_path or ""
                            break
                        end
                    end

                    if errorCount == 0 and uploadedCount == photoCount then
                        local confirm = LrDialogs.confirm(
                            Api.getTitle("Upload abgeschlossen!"), 
                            "Alle Bilder (" .. photoCount .. ") wurden erfolgreich hochgeladen.\n\nMöchtest du die Galerie jetzt im Web-Portal öffnen, um sie zu überprüfen und Kunden zu benachrichtigen?", 
                            "Im Web öffnen", 
                            "Schließen"
                        )
                        if confirm == "ok" and selectedGalPath ~= "" then
                            local galPath = selectedGalPath:gsub("^/", "")
                            local url = Api.baseUrl .. "/" .. galPath
                            LrHttp.openUrlInBrowser(url)
                        end
                    else
                        LrDialogs.message(
                            Api.getTitle("Upload mit Fehlern abgeschlossen"), 
                            failedCount .. " von " .. photoCount .. " Bildern konnten nicht hochgeladen werden.\n\nBitte prüfe die aufgetretenen Fehlermeldungen oder die Log-Datei im Temp-Ordner.",
                            "warning"
                        )
                    end
                    logMsg("=== UPLOAD SCHLEIFE BEENDET ===")

                    if errorCount == 0 and uploadedCount == photoCount then
                        LrFileUtils.delete(galleryUploadDir)
                        if LrFileUtils.exists(galleryUploadDir) then
                            -- The directory is disposable after a complete
                            -- upload; retain the diagnostic message in the UI
                            -- log when the SDK reports a cleanup failure.
                            logMsg("Konnte Temp-Verzeichnis nicht löschen.")
                        end
                    end
                end)
            end
        end)
    end)
end
