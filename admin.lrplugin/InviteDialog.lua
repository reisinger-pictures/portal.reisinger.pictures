local LrView = import 'LrView'
local LrDialogs = import 'LrDialogs'
local LrBinding = import 'LrBinding'
local LrFunctionContext = import 'LrFunctionContext'
local LrTasks = import 'LrTasks'
local Api = require "Api"

return function(galleryId, jwt)
    LrFunctionContext.callWithContext("InviteManagerContext", function(context)
        local f = LrView.osFactory()
        local props = LrBinding.makePropertyTable(context)
        
        props.newInviteName = ""
        props.linkType = "mass"
        props.generatedLink = ""
        props.invites = {{title = "Lade...", value = -1}}
        props.selectedInviteId = -1
        props.selectedInviteLink = ""

        local function loadInvites()
            LrTasks.startAsyncTask(function()
                local data, status = Api.call("/api/management/galleries/" .. galleryId .. "/invites", "GET", nil, jwt)
                if status == 200 and data then
                    local items = {}
                    for _, inv in ipairs(data) do
                        local name = (inv.name and inv.name ~= "") and inv.name or "Anonym"
                        table.insert(items, { title = name .. " (" .. string.sub(inv.token, 1, 8) .. "...)", value = inv.id, token = inv.token })
                    end
                    if #items == 0 then table.insert(items, { title = "Keine Einladungen vorhanden", value = -1 }) end
                    props.invites = items
                    props.selectedInviteId = items[1].value
                end
            end)
        end
        loadInvites()

        props:addObserver("selectedInviteId", function()
            if props.selectedInviteId == -1 then
                props.selectedInviteLink = ""
            else
                for _, item in ipairs(props.invites) do
                    if item.value == props.selectedInviteId then
                        props.selectedInviteLink = Api.getApiUrl() .. "/invite/" .. item.token
                        break
                    end
                end
            end
        end)

        local contents = f:column {
            spacing = f:control_spacing(),
            width = 600, -- Erzwungene Breite für den Dialog
            f:group_box {
                title = "Neuen Link generieren", fill_horizontal = 1,
                f:column {
                    spacing = f:control_spacing(),
                    f:row { f:radio_button { title = "Massen-Link (Gäste geben E-Mail & Name selbst ein)", value = LrView.bind{key="linkType", bind_to_object=props}, checked_value = "mass" } },
                    f:row { f:radio_button { title = "Persönlicher Link (Direkter Login ohne Eingabe)", value = LrView.bind{key="linkType", bind_to_object=props}, checked_value = "personal" } },
                    f:row { 
                        visible = LrView.bind { key = "linkType", bind_to_object = props, transform = function(v) return v == "personal" end },
                        f:spacer { width = 20 },
                        f:static_text { title = "Name des Gastes:", width = 100 }, 
                        f:edit_field { value = LrView.bind{key="newInviteName", bind_to_object=props}, fill_horizontal = 1 } 
                    },
                    f:row { 
                        f:spacer { width = 120 }, 
                        f:push_button { 
                            title = "Generieren", 
                            action = function()
                                LrTasks.startAsyncTask(function()
                                    local payload = {}
                                    if props.linkType == "personal" and props.newInviteName ~= "" then payload.name = props.newInviteName end
                                    local resData, stat = Api.call("/api/management/galleries/" .. galleryId .. "/invites", "POST", payload, jwt)
                                    if stat == 200 and resData and resData.link then
                                        props.generatedLink = resData.link
                                        props.newInviteName = ""
                                        loadInvites()
                                    end
                                end)
                            end 
                        } 
                    },
                    f:row { f:static_text { title = "Link kopieren:", width = 120 }, f:edit_field { value = LrView.bind{key="generatedLink", bind_to_object=props}, fill_horizontal = 1 } }
                }
            },
            f:spacer { height = 15 },
            f:group_box {
                title = "Bestehende Links verwalten", fill_horizontal = 1,
                f:column {
                    spacing = f:control_spacing(),
                    f:row { f:static_text { title = "Einladung:", width = 120 }, f:popup_menu { items = LrView.bind{key="invites", bind_to_object=props}, value = LrView.bind{key="selectedInviteId", bind_to_object=props}, fill_horizontal = 1 } },
                    f:row { f:static_text { title = "Link kopieren:", width = 120 }, f:edit_field { value = LrView.bind{key="selectedInviteLink", bind_to_object=props}, fill_horizontal = 1 } },
                    f:row { 
                        f:spacer { width = 120 }, 
                        f:push_button { 
                            title = "Widerrufen", 
                            enabled = LrView.bind { key = "selectedInviteId", bind_to_object = props, transform = function(v) return v ~= -1 end }, 
                            action = function()
                                local confirm = LrDialogs.confirm(Api.getTitle("Widerrufen?"), "Link wird sofort ungültig.", "Widerrufen", "Abbrechen")
                                if confirm == "ok" then
                                    LrTasks.startAsyncTask(function()
                                        local _, stat = Api.call("/api/management/invites/" .. props.selectedInviteId, "DELETE", nil, jwt)
                                        if stat == 200 then props.selectedInviteLink = ""; loadInvites() end
                                    end)
                                end
                            end 
                        } 
                    }
                }
            }
        }

        LrDialogs.presentModalDialog { 
            title = Api.getTitle("Einladungs-Links verwalten"), 
            contents = contents, 
            cancelVerb = "< exclude >", 
            actionVerb = "Schließen" 
        }
    end)
end
