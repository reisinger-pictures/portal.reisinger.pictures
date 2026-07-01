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
                title = "Portal API Einstellungen (B2B & SRP)",
                
                f:row {
                    f:static_text { title = "B2B (reisinger.pictures):", width = 150 },
                    f:edit_field {
                        value = LrView.bind { key = "baseUrlRp", bind_to_object = prefs },
                        fill_horizontal = 1
                    }
                },

                f:row {
                    f:static_text { title = "SRP (buy.reisinger.pictures):", width = 150 },
                    f:edit_field {
                        value = LrView.bind { key = "baseUrlSrp", bind_to_object = prefs },
                        fill_horizontal = 1
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
                        title = "Login testen (B2B)",
                        action = function()
                            LrTasks.startAsyncTask(function()
                                local url = (prefs.baseUrlRp and #prefs.baseUrlRp > 0) and prefs.baseUrlRp or "https://portal.reisinger.pictures"
                                local token, err, detail = Api.login(url)
                                if token then
                                    LrDialogs.message("Erfolg!", "Verbindung zum B2B-Portal erfolgreich hergestellt.\nDer Token wird ab sofort automatisch verwaltet.", "info")
                                else
                                    LrDialogs.message("Fehlgeschlagen", "Fehler: " .. tostring(err) .. "\n\n" .. tostring(detail), "critical")
                                end
                            end)
                        end
                    }
                },
                
                f:row {
                    f:spacer { width = 150 },
                    f:push_button {
                        title = "Login testen (SRP)",
                        action = function()
                            LrTasks.startAsyncTask(function()
                                local url = (prefs.baseUrlSrp and #prefs.baseUrlSrp > 0) and prefs.baseUrlSrp or "https://buy.reisinger.pictures"
                                local token, err, detail = Api.login(url)
                                if token then
                                    LrDialogs.message("Erfolg!", "Verbindung zum SRP-Portal erfolgreich hergestellt.\nDer Token wird ab sofort automatisch verwaltet.", "info")
                                else
                                    LrDialogs.message("Fehlgeschlagen", "Fehler: " .. tostring(err) .. "\n\n" .. tostring(detail), "critical")
                                end
                            end)
                        end
                    }
                },
                
                f:row {
                    f:static_text { title = "Hinweis:", width = 150 },
                    f:static_text { title = "Deine Fotografen-Zugangsdaten. Gleiche Credentials für beide Portale." }
                }
            }
        }
    end
}
