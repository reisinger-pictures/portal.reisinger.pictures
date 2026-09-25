local LrView = import 'LrView'
local LrPrefs = import 'LrPrefs'
local LrTasks = import 'LrTasks'
local LrDialogs = import 'LrDialogs'
local Api = require "Api"

return {
    sectionsForTopOfDialog = function(f, propertyTable)
        local prefs = LrPrefs.prefsForPlugin()
        -- The password field is intentionally left empty; the saved password is
        -- read from the OS credential store only when a login is performed.
        propertyTable.apiUser = prefs.apiUser or ""
        propertyTable.apiPass = ""

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
                        value = LrView.bind { key = "apiUser", bind_to_object = propertyTable },
                        fill_horizontal = 1,
                        immediate = true
                    }
                },
                
                f:row {
                    f:static_text { title = "Passwort:", width = 150 },
                    f:password_field {
                        value = LrView.bind { key = "apiPass", bind_to_object = propertyTable },
                        fill_horizontal = 1,
                        immediate = true
                    }
                },
                
                f:row {
                    f:spacer { width = 150 },
                    f:push_button {
                        title = "Login testen",
                        action = function()
                            LrTasks.startAsyncTask(function()
                                local migrationOk = Api.migrateLegacyPassword()
                                if migrationOk == false then
                                    LrDialogs.message(
                                        "Passwortmigration fehlgeschlagen",
                                        "Das alte Passwort konnte nicht in den Betriebssystem-Schlüsselbund übernommen werden. Bitte gib das Passwort erneut ein.",
                                        "warning"
                                    )
                                end
                                prefs.apiUser = propertyTable.apiUser or ""
                                local typedPassword = propertyTable.apiPass
                                local password = (typedPassword and typedPassword ~= "") and typedPassword or Api.getStoredPassword()
                                local token, err, detail = Api.login(propertyTable.apiUser, password)
                                if token then
                                    local stored = true
                                    if password and password ~= "" then
                                        stored = Api.storePassword(password)
                                    end
                                    if stored then
                                        LrDialogs.message("Erfolg!", "Verbindung zum Portal erfolgreich hergestellt. Das Passwort wird sicher im Betriebssystem gespeichert.", "info")
                                    else
                                        LrDialogs.message("Verbindung erfolgreich, Passwort nicht gespeichert", "Die Anmeldung war erfolgreich, das Passwort konnte aber nicht im Betriebssystem-Schlüsselbund gespeichert werden.", "warning")
                                    end
                                else
                                    LrDialogs.message("Fehlgeschlagen", "Fehler: " .. tostring(err) .. "\n\n" .. tostring(detail), "critical")
                                end
                            end)
                        end
                    }
                },
                
                f:row {
                    f:static_text { title = "Hinweis:", width = 150 },
                    f:static_text { title = "Das Passwort wird im Betriebssystem-Schlüsselbund gespeichert, nicht in den Plugin-Einstellungen." }
                }
            }
        }
    end
}
