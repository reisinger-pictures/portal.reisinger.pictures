-- admin.lrplugin/GalleryDialog.lua
local LrView = import 'LrView'
local LrDialogs = import 'LrDialogs'
local LrBinding = import 'LrBinding'
local LrFunctionContext = import 'LrFunctionContext'
local LrTasks = import 'LrTasks'
local Api = require "Api"
local Utils = require "Utils"

return function(mode, editingGallery, treeData, jwt, onSuccess)
    LrFunctionContext.callWithContext("GalleryDialogContext", function(context)
        local f = LrView.osFactory()
        local props = LrBinding.makePropertyTable(context)

        props.gName = editingGallery and editingGallery.name or ""
        props.gSlug = editingGallery and editingGallery.slug or ""
        props.slugEdited = editingGallery and true or false
        props.gPublic = editingGallery and (editingGallery.is_public and "true" or "false") or "false"
        props.gGroup = editingGallery and (editingGallery.gallery_group_id or "") or ""
        props.gLive = (editingGallery and editingGallery.is_live) == true
        props.gFreeDownload = editingGallery and (editingGallery.is_free_download == true) or false
        props.gEditorialOnly = editingGallery and (editingGallery.is_editorial_only == true) or false
        props.gHidden = editingGallery and (editingGallery.is_hidden == true) or false
        props.gPassword = ""
        props.gExpiresAt = ""
        
        -- Metadaten & Berechtigungen
        props.allowClientMeta = editingGallery and (editingGallery.allow_client_metadata_edit == true) or false
        props.applyMeta = editingGallery and (editingGallery.apply_metadata_to_photos == true) or false
        props.defTitle = editingGallery and editingGallery.default_title or ""
        props.defHeadline = editingGallery and editingGallery.default_headline or ""
        props.defDesc = editingGallery and editingGallery.default_description or ""
        props.defKeywords = editingGallery and editingGallery.default_keywords or ""
        props.defLocation = editingGallery and editingGallery.default_location or ""
        props.defCity = editingGallery and editingGallery.default_city or ""
        props.defState = editingGallery and editingGallery.default_state or ""
        props.defCountry = editingGallery and editingGallery.default_country or ""
        props.defIso = editingGallery and editingGallery.default_iso_country or ""
        
        if editingGallery and editingGallery.expires_at and editingGallery.expires_at ~= "" then
            -- Laravel schickt meist ISO Format "YYYY-MM-DDTHH:mm:ss...", wir brauchen nur die ersten 10 Zeichen
            props.gExpiresAt = string.sub(editingGallery.expires_at, 1, 10)
        end

        local isAutoUpdating = false
        props:addObserver("gName", function()
            if not props.slugEdited then 
                isAutoUpdating = true
                props.gSlug = Utils.slugify(props.gName) 
                isAutoUpdating = false
            end
        end)
        props:addObserver("gSlug", function() 
            if not isAutoUpdating then props.slugEdited = true end
        end)

        local groupItems = { { title = "-- Keine (Root Ebene) --", value = "" } }
        for _, g in ipairs(Utils.flattenGroups(treeData.groups)) do table.insert(groupItems, g) end

        local rows = { spacing = f:control_spacing() }

        table.insert(rows, f:row {
            f:static_text { title = "Name:", width = 120 },
            f:edit_field { value = LrView.bind{key="gName", bind_to_object=props}, fill_horizontal = 1, width_in_chars = 40 }
        })
        table.insert(rows, f:row {
            f:static_text { title = "Slug (URL):", width = 120 },
            f:edit_field { value = LrView.bind{key="gSlug", bind_to_object=props}, fill_horizontal = 1, width_in_chars = 40 }
        })
        table.insert(rows, f:row {
            f:static_text { title = "In Meta-Galerie:", width = 120 },
            f:popup_menu { items = groupItems, value = LrView.bind{key="gGroup", bind_to_object=props}, fill_horizontal = 1 }
        })

        if mode == "selection" then
            table.insert(rows, f:row {
                f:static_text { title = "Sichtbarkeit:", width = 120 },
                f:static_text { title = "Privat (Auswahl-Galerien sind immer privat)", text_color = LrView.color(0.5,0.5,0.5) }
            })
        else
            table.insert(rows, f:row {
                f:static_text { title = "Sichtbarkeit:", width = 120 },
                f:popup_menu {
                    items = { {title="Privat (Passwort/Link)", value="false"}, {title="Öffentlich", value="true"} },
                    value = LrView.bind{key="gPublic", bind_to_object=props}, fill_horizontal = 1
                }
            })
            table.insert(rows, f:row {
                f:spacer { width = 120 },
                f:checkbox { title = "LIVE Galerie (Auto-Refresh für Gäste im Web)", value = LrView.bind{key="gLive", bind_to_object=props} }
            })
        end

        table.insert(rows, f:row {
            f:static_text { title = "Ablaufdatum:", width = 120 },
            f:edit_field { value = LrView.bind{key="gExpiresAt", bind_to_object=props}, fill_horizontal = 1, placeholder_string = "YYYY-MM-DD (Optional)", width_in_chars = 40 }
        })

        table.insert(rows, f:row {
            f:static_text { title = "Passwort (Optional):", width = 120 },
            f:edit_field { value = LrView.bind{key="gPassword", bind_to_object=props}, fill_horizontal = 1, placeholder_string = editingGallery and "(Unverändert lassen)" or "" }
        })

        -- METADATEN BLOCK (Nur bei Delivery Galerien)
        if mode == "delivery" then
            table.insert(rows, f:spacer { height = 10 })
            table.insert(rows, f:separator { fill_horizontal = 1 })
            table.insert(rows, f:spacer { height = 5 })
            table.insert(rows, f:static_text { title = "Metadaten & Berechtigungen", font = "<system/bold>" })

            table.insert(rows, f:row {
                f:spacer { width = 120 },
                f:checkbox { title = "Kostenlosen Download erlauben", value = LrView.bind{key="gFreeDownload", bind_to_object=props} }
            })
            table.insert(rows, f:row {
                f:spacer { width = 120 },
                f:checkbox { title = "Nur für redaktionelle Nutzung (Shop)", value = LrView.bind{key="gEditorialOnly", bind_to_object=props} }
            })
            table.insert(rows, f:row {
                f:spacer { width = 120 },
                f:checkbox { title = "Im Frontend verstecken", value = LrView.bind{key="gHidden", bind_to_object=props} }
            })

            table.insert(rows, f:row {
                f:spacer { width = 120 },
                f:checkbox { title = "Kunden dürfen Metadaten bearbeiten", value = LrView.bind{key="allowClientMeta", bind_to_object=props} }
            })

            table.insert(rows, f:row {
                f:spacer { width = 120 },
                f:checkbox { title = "Standard-Metadaten beim Upload anwenden", value = LrView.bind{key="applyMeta", bind_to_object=props} }
            })

            -- Eingabefelder für Defaults (nur sichtbar wenn applyMeta true ist)
            local iptcFields = f:column {
                spacing = f:control_spacing(),
                margin_top = 10,
                f:row { f:static_text { title="Titel:", width=120 }, f:edit_field { value = LrView.bind{key="defTitle", bind_to_object=props}, fill_horizontal=1, width_in_chars=40 } },
                f:row { f:static_text { title="Headline:", width=120 }, f:edit_field { value = LrView.bind{key="defHeadline", bind_to_object=props}, fill_horizontal=1, width_in_chars=40 } },
                f:row { f:static_text { title="Beschreibung:", width=120 }, f:edit_field { value = LrView.bind{key="defDesc", bind_to_object=props}, fill_horizontal=1, width_in_chars=40, height_in_lines=4 } },
                f:row { f:static_text { title="Keywords:", width=120 }, f:edit_field { value = LrView.bind{key="defKeywords", bind_to_object=props}, fill_horizontal=1, width_in_chars=40 } },
                f:row { 
                    f:static_text { title="PLZ / Ort:", width=120 }, 
                    f:edit_field { value = LrView.bind{key="defLocation", bind_to_object=props}, width_in_chars=10, placeholder_string="PLZ" },
                    f:spacer { width = 5 },
                    f:edit_field { value = LrView.bind{key="defCity", bind_to_object=props}, fill_horizontal=1, placeholder_string="Stadt" } 
                },
                f:row { f:static_text { title="Bundesland:", width=120 }, f:edit_field { value = LrView.bind{key="defState", bind_to_object=props}, fill_horizontal=1, width_in_chars=40 } },
                f:row { 
                    f:static_text { title="Land / ISO:", width=120 }, 
                    f:edit_field { value = LrView.bind{key="defCountry", bind_to_object=props}, fill_horizontal=1, placeholder_string="Land" },
                    f:spacer { width = 5 },
                    f:edit_field { value = LrView.bind{key="defIso", bind_to_object=props}, width_in_chars=5, placeholder_string="DE" }
                }
            }

            table.insert(rows, f:row {
                visible = LrView.bind{key="applyMeta", bind_to_object=props},
                iptcFields
            })
        end

        local res = LrDialogs.presentModalDialog {
            title = Api.getTitle(editingGallery and "Galerie bearbeiten" or "Neue Galerie anlegen"),
            contents = f:column(rows),
            actionVerb = editingGallery and "Speichern" or "Erstellen",
            cancelVerb = "Abbrechen"
        }

        if res == "ok" and props.gName ~= "" then
            LrTasks.startAsyncTask(function()
                local payload = {
                    name = props.gName,
                    slug = props.gSlug,
                    type = mode,
                    is_public = (mode == "selection") and false or (props.gPublic == "true"),
                    is_live = (mode == "delivery") and props.gLive or false,
                    gallery_group_id = props.gGroup == "" and nil or props.gGroup
                }
                
                if props.gPassword ~= "" then payload.password = props.gPassword end
                if props.gExpiresAt ~= "" then payload.expires_at = props.gExpiresAt end

                -- Metadaten-Settings integrieren (Laravel konvertiert leere Strings in Datenbank-NULLs)
                if mode == "delivery" then
                    payload.is_free_download = props.gFreeDownload
                    payload.is_editorial_only = props.gEditorialOnly
                    payload.is_hidden = props.gHidden
                    payload.allow_client_metadata_edit = props.allowClientMeta
                    payload.apply_metadata_to_photos = props.applyMeta
                    payload.default_title = props.defTitle
                    payload.default_headline = props.defHeadline
                    payload.default_description = props.defDesc
                    payload.default_keywords = props.defKeywords
                    payload.default_location = props.defLocation
                    payload.default_city = props.defCity
                    payload.default_state = props.defState
                    payload.default_country = props.defCountry
                    payload.default_iso_country = props.defIso
                end

                local endpoint = editingGallery and ("/api/management/galleries/" .. editingGallery.id) or "/api/management/galleries"
                local apiMethod = editingGallery and "PUT" or "POST"
                
                local data, status = Api.call(endpoint, apiMethod, payload, jwt)
                if status == 200 then
                    if onSuccess then onSuccess() end
                else
                    LrDialogs.message(Api.getTitle("Fehler"), "Galerie konnte nicht gespeichert werden.", "critical")
                end
            end)
        end
    end)
end
