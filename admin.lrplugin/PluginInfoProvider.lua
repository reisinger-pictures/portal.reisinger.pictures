local LrView = import 'LrView'
local LrPrefs = import 'LrPrefs'
local LrTasks = import 'LrTasks'
local LrDialogs = import 'LrDialogs'
local Api = require "Api"

return {
    sectionsForTopOfDialog = function(f, propertyTable)
        local prefs = LrPrefs.prefsForPlugin()
        
        return {
            {
                title = "Portal API Einstellungen",
                
                f:row {
                    f:checkbox {
                        title = "Lokale Entwicklungsumgebung nutzen (localhost:4321)",
                        value = LrView.bind { key = "useLocal", bind_to_object = prefs }
                    }
                },

                f:separator { fill_horizontal = 1 },

                f:row {
                    f:static_text { title = "E-Mail:", width = 150 },
                    f:edit_field {
                        value = LrView.bind { key = "apiUser", bind_to_object = prefs },
                        fill_horizontal = 1
                    }
                },
                
                f:row {
                    f:static_text { title = "Passwort:", width = 150 },
                    f:password_field {
                        value = LrView.bind { key = "apiPass", bind_to_object = prefs },
                        fill_horizontal = 1
                    }
                },
                
                f:row {
                    f:spacer { width = 150 },
                    f:push_button {
                        title = "Login testen",
                        action = function()
                            LrTasks.startAsyncTask(function()
                                local token, err, detail = Api.login()
                                if token then
                                    LrDialogs.message("Erfolg!", "Verbindung zum Portal erfolgreich hergestellt.\nDer Token wird ab sofort automatisch verwaltet.", "info")
                                else
                                    LrDialogs.message("Fehlgeschlagen", "Fehler: " .. tostring(err) .. "\n\n" .. tostring(detail), "critical")
                                end
                            end)
                        end
                    }
                },
                
                f:row {
                    f:static_text { title = "Hinweis:", width = 150 },
                    f:static_text { title = "Deine Fotografen-Zugangsdaten für das Portal." }
                }
            }
        }
    end
}
