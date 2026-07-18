local LrHttp = import 'LrHttp'
local LrPrefs = import 'LrPrefs'
local LrTasks = import 'LrTasks'
local json = require "json"

local Api = {}

Api.baseUrl = "https://portal.reisinger.pictures"

function Api.setBaseUrl(url)
    Api.baseUrl = url
end

function Api.getTitle(title)
    return title
end

function Api.call(endpoint, method, payload, jwt)
    local headers = {}
    table.insert(headers, { field = "Referer", value = Api.baseUrl })
    if jwt then table.insert(headers, { field = "Authorization", value = "Bearer " .. jwt }) end
    local payloadStr = ""
    if payload then
        table.insert(headers, { field = "Content-Type", value = "application/json" })
        payloadStr = json.encode(payload)
    end
    
    local resBody, resHeaders
    local fullUrl = Api.baseUrl .. endpoint
    
    for retry = 0, 1 do
        if method == "GET" then
            resBody, resHeaders = LrHttp.get(fullUrl, headers)
        elseif method == "PUT" or method == "DELETE" then
            table.insert(headers, { field = "X-HTTP-Method-Override", value = method })
            resBody, resHeaders = LrHttp.post(fullUrl, payloadStr, headers)
        else
            resBody, resHeaders = LrHttp.post(fullUrl, payloadStr, headers)
        end
        
        local status = resHeaders and resHeaders.status or 500
        local isServerError = (status >= 500) or (resHeaders and resHeaders.error)
        if retry == 0 and isServerError then
            LrTasks.sleep(2)
        else
            break
        end
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
    Api.setBaseUrl(prefs.useLocal and "http://localhost:4321" or "https://portal.reisinger.pictures")
    local payload = { email = prefs.apiUser, password = prefs.apiPass }
    local data, status, resBody, resHeaders

    data, status, resBody, resHeaders = Api.call("/api/auth/login", "POST", payload, nil)
    
    local token = data and data.access_token
    
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
    local detail = "Status: " .. tostring(status) .. "\nURL: " .. Api.baseUrl .. "/api/auth/login\n"
    if resBody and resBody ~= "" then detail = detail .. "Body: " .. string.sub(resBody, 1, 300) end
    if resHeaders and resHeaders.error then detail = detail .. "\nCurl Error: " .. tostring(resHeaders.error.localizedMessage) end
    
    return nil, err, detail
end

function Api.uploadMultipart(endpoint, formFields, jwt)
    local fullUrl = Api.baseUrl .. endpoint
    local headers = { { field = "Authorization", value = "Bearer " .. jwt } }

    local resBody, resHeaders = LrHttp.postMultipart(fullUrl, formFields, headers)
    local status = resHeaders and resHeaders.status or 500

    if status >= 500 or (resHeaders and resHeaders.error) then
        LrTasks.sleep(2)
        resBody, resHeaders = LrHttp.postMultipart(fullUrl, formFields, headers)
        status = resHeaders and resHeaders.status or 500
    end

    if status >= 200 and status < 300 then
        return resBody, status
    else
        local errDetail = resHeaders and resHeaders.error and resHeaders.error.localizedMessage or (resBody or "HTTP " .. tostring(status))
        return nil, errDetail
    end
end

function Api.checkRole(jwt)
    local data, status = Api.call("/api/auth/me", "GET", nil, jwt)
    if status == 200 and data then
        if data.is_photographer or data.is_admin or data.is_super_admin then return true, data end
    end
    return false, nil
end

return Api
