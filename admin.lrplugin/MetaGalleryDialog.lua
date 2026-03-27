local LrView = import 'LrView'
local LrDialogs = import 'LrDialogs'
local LrBinding = import 'LrBinding'
local LrFunctionContext = import 'LrFunctionContext'
local LrTasks = import 'LrTasks'
local Api = require "Api"
local Utils = require "Utils"

return function(editingGroup, treeData, jwt, onSuccess)
    LrFunctionContext.callWithContext("MetaGalleryDialogContext", function(context)
        local f = LrView.osFactory()
        local props = LrBinding.makePropertyTable(context)

        props.gName = editingGroup and editingGroup.name or ""
        props.gSlug = editingGroup and editingGroup.slug or ""
        props.slugEdited = editingGroup and true or false
        props.gPublic = editingGroup and (editingGroup.is_public == nil and "null" or (editingGroup.is_public and "true" or "false")) or "null"
        props.gParent = editingGroup and (editingGroup.parent_id or "") or ""

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
        for _, g in ipairs(Utils.flattenGroups(treeData.groups)) do 
            if not editingGroup or g.value ~= editingGroup.id then
                table.insert(parentItems, g) 
            end
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
            LrTasks.startAsyncTask(function()
                local isPub = nil
                if props.gPublic == "true" then isPub = true elseif props.gPublic == "false" then isPub = false end
                local payload = {
                    name = props.gName,
                    slug = props.gSlug,
                    is_public = isPub,
                    parent_id = props.gParent == "" and nil or props.gParent
                }
                
                local endpoint = editingGroup and ("/api/management/gallery-groups/" .. editingGroup.id) or "/api/management/gallery-groups"
                local apiMethod = editingGroup and "PUT" or "POST"
                
                local data, status = Api.call(endpoint, apiMethod, payload, jwt)
                if status == 200 then
                    if onSuccess then onSuccess() end
                else
                    LrDialogs.message(Api.getTitle("Fehler"), "Meta-Galerie konnte nicht gespeichert werden.", "critical")
                end
            end)
        end
    end)
end
