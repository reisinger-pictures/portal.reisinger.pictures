local LrView = import 'LrView'
local LrDialogs = import 'LrDialogs'
local LrBinding = import 'LrBinding'
local LrFunctionContext = import 'LrFunctionContext'
local LrTasks = import 'LrTasks'
local Api = require "Api"
local Utils = require "Utils"

return function(editingGroup, treeData, jwt, onSuccess, requestApi)
    LrFunctionContext.callWithContext("MetaGalleryDialogContext", function(context)
        local f = LrView.osFactory()
        local props = LrBinding.makePropertyTable(context)
        local function apiRequest(endpoint, method, payload)
            if requestApi then return requestApi(endpoint, method, payload) end
            return Api.call(endpoint, method, payload, jwt)
        end

        props.gName = editingGroup and editingGroup.name or ""
        props.gSlug = editingGroup and editingGroup.slug or ""
        props.slugEdited = editingGroup and true or false
        props.gPublic = editingGroup and (editingGroup.is_public == nil and "null" or (editingGroup.is_public and "true" or "false")) or "null"
        props.gParent = editingGroup and (editingGroup.parent_id or "") or ""
        props.gFreeDownload = editingGroup and (editingGroup.is_free_download == true) or false
        props.gEditorialOnly = editingGroup and (editingGroup.is_editorial_only == true) or false
        props.gHidden = editingGroup and (editingGroup.is_hidden == true) or false

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

        local parentItems = { {title="-- Keine (Root Ebene) --", value=""} }
        -- A group cannot be its own parent, nor can any descendant be chosen:
        -- the latter would create a cycle when the server persists parent_id.
        for _, g in ipairs(Utils.flattenGroupChoices(treeData.groups, editingGroup)) do
            table.insert(parentItems, g)
        end

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
            f:spacer { width = 120 },
            f:checkbox { title = "Kostenlosen Download erlauben", value = LrView.bind{key="gFreeDownload", bind_to_object=props} }
        })
        table.insert(rows, f:row {
            f:spacer { width = 120 },
            f:checkbox { title = "Nur redaktionelle Nutzung (Shop)", value = LrView.bind{key="gEditorialOnly", bind_to_object=props} }
        })
        table.insert(rows, f:row {
            f:spacer { width = 120 },
            f:checkbox { title = "Im Frontend verstecken", value = LrView.bind{key="gHidden", bind_to_object=props} }
        })
        table.insert(rows, f:row {
            f:static_text { title = "Sichtbarkeit:", width = 120 },
            f:popup_menu {
                items = { {title="Keine Vorgabe (Unterordner entscheiden)", value="null"}, {title="Privat erzwingen", value="false"}, {title="Öffentlich erzwingen", value="true"} },
                value = LrView.bind{key="gPublic", bind_to_object=props}, fill_horizontal = 1
            }
        })
        table.insert(rows, f:row {
            f:static_text { title = "Unterordner von:", width = 120 },
            f:popup_menu { items = parentItems, value = LrView.bind{key="gParent", bind_to_object=props}, fill_horizontal = 1 }
        })

        local res = LrDialogs.presentModalDialog {
            title = Api.getTitle(editingGroup and "Meta-Galerie bearbeiten" or "Neue Meta-Galerie anlegen"),
            contents = f:column(rows),
            actionVerb = editingGroup and "Speichern" or "Erstellen",
            cancelVerb = "Abbrechen"
        }

        if res == "ok" and props.gName ~= "" then
            local parentId = props.gParent or ""
            if not Utils.isGroupParentSelectionValid(treeData.groups, editingGroup, parentId) then
                LrDialogs.message(
                    Api.getTitle("Ungültiger Unterordner"),
                    "Eine Meta-Galerie kann nicht als eigener Unterordner oder als Elternteil eines ihrer Unterordner ausgewählt werden.",
                    "warning"
                )
                return
            end

            LrTasks.startAsyncTask(function()
                local isPub = nil
                if props.gPublic == "true" then isPub = true elseif props.gPublic == "false" then isPub = false end
                local payload = {
                    name = props.gName,
                    slug = props.gSlug,
                    is_public = isPub,
                    parent_id = parentId == "" and nil or parentId,
                    is_free_download = props.gFreeDownload,
                    is_editorial_only = props.gEditorialOnly,
                    is_hidden = props.gHidden
                }
                
                local endpoint = editingGroup and ("/api/management/gallery-groups/" .. editingGroup.id) or "/api/management/gallery-groups"
                local apiMethod = editingGroup and "PUT" or "POST"
                
                local data, status = apiRequest(endpoint, apiMethod, payload)
                if status == 200 then
                    if onSuccess then onSuccess() end
                else
                    LrDialogs.message(Api.getTitle("Fehler"), "Meta-Galerie konnte nicht gespeichert werden.", "critical")
                end
            end)
        end
    end)
end
