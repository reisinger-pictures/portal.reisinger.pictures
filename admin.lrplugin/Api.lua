local LrHttp = import 'LrHttp'
local LrPrefs = import 'LrPrefs'
local json = require "json"

local Api = {}

function Api.getApiUrl()
    local prefs = LrPrefs.prefsForPlugin()
    return prefs.useTestUrl and "https://portal.test" or "https://portal.reisinger.pictures"
end

function Api.getTitle(title)
    local prefs = LrPrefs.prefsForPlugin()
    return prefs.useTestUrl and ("[TEST] " .. title) or title
end

function Api.call(endpoint, method, payload, jwt)
    local headers = {}
    if jwt then table.insert(headers, { field = "Authorization", value = "Bearer " .. jwt }) end
    local payloadStr = ""
    if payload then
        table.insert(headers, { field = "Content-Type", value = "application/json" })
        payloadStr = json.encode(payload)
    end
    
    local resBody, resHeaders
    local fullUrl = Api.getApiUrl() .. endpoint
    
    if method == "GET" then
        resBody, resHeaders = LrHttp.get(fullUrl, headers)
    elseif method == "PUT" or method == "DELETE" then
        table.insert(headers, { field = "X-HTTP-Method-Override", value = method })
        resBody, resHeaders = LrHttp.post(fullUrl, payloadStr, headers)
    else
        resBody, resHeaders = LrHttp.post(fullUrl, payloadStr, headers)
    end
    
    local status = resHeaders and resHeaders.status or 500
    local data = nil
    if resBody and resBody ~= "" then
        local success, parsed = pcall(json.decode, resBody)
        if success then data = parsed end
    end
    return data, status, resBody, resHeaders
end

function Api.login()
    local prefs = LrPrefs.prefsForPlugin()
    if not prefs.apiUser or not prefs.apiPass then return nil, "Keine Zugangsdaten eingegeben.", "" end
    local payload = { email = prefs.apiUser, password = prefs.apiPass }
    local data, status, resBody, resHeaders = Api.call("/api/auth/login", "POST", payload, nil)
    
    local token = data and data.access_token
    
    -- LrHttp gibt resHeaders als Liste von Tabellen zurück: { {field="Set-Cookie", value="..."} }
    if status == 200 and resHeaders and not token then
        for _, header in ipairs(resHeaders) do
            if type(header) == "table" and header.field and string.lower(header.field) == "set-cookie" then
                if header.value then
                    local match = string.match(header.value, "rp_jwt=([^;]+)")
                    if match then 
                        token = match
                        break 
                    end
                end
            end
        end
    end
    
    if status == 200 and token then return token, nil, nil end
    
    local err = (data and data.error) or "Unbekannter API Fehler"
    local detail = "Status: " .. tostring(status) .. "\nURL: " .. Api.getApiUrl() .. "/api/auth/login\n"
    if resBody and resBody ~= "" then detail = detail .. "Body: " .. string.sub(resBody, 1, 300) end
    if resHeaders and resHeaders.error then detail = detail .. "\nCurl Error: " .. tostring(resHeaders.error.localizedMessage) end
    
    return nil, err, detail
end

function Api.checkRole(jwt)
    local data, status = Api.call("/api/auth/me", "GET", nil, jwt)
    if status == 200 and data then
        if data.is_photographer or data.is_admin then return true, data end
    end
    return false, nil
end

return Api
