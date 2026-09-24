local LrHttp = import 'LrHttp'
local LrPrefs = import 'LrPrefs'
local LrTasks = import 'LrTasks'
local LrPasswords = import 'LrPasswords'
local json = require "json"

local Api = {}

Api.baseUrl = "https://portal.reisinger.pictures"

-- The portal password is kept in the OS-protected credential store
-- (macOS Keychain / Windows Credential Manager) via the Lightroom SDK's
-- LrPasswords namespace. Lightroom scopes the key by plug-in ID, so no other
-- plug-in can read it. Only this key is remembered; LrPrefs never holds the
-- secret itself.
local CREDENTIAL_KEY = "portalPassword"

-- LrHttp timeout (seconds) waited during each phase of a connection before
-- the request is cancelled. Keeps the UI from hanging on a dead connection.
local REQUEST_TIMEOUT = 60
local UPLOAD_TIMEOUT = 600

-- A single request may re-authenticate and retry at most once.  The bound is
-- deliberately per request, not per manager: a fresh token can itself expire
-- during a very long export, while a failed request must never loop.
local MAX_AUTH_RETRIES = 1
Api.MAX_AUTH_RETRIES = MAX_AUTH_RETRIES
-- Kept as a public compatibility alias for older plug-in test harnesses.
Api.MAX_SESSION_REFRESHES = MAX_AUTH_RETRIES

local function isAuthSession(value)
    return type(value) == "table" and value._authSession == true
end

function Api.setBaseUrl(url)
    Api.baseUrl = url
end

function Api.getTitle(title)
    return title
end

-- Stores the portal password in the OS credential store. Passing an empty
-- value is a no-op (LrPasswords has no delete operation). Returns true on
-- success; a keychain/credential-store error is reported as false.
function Api.storePassword(password)
    if password and password ~= "" then
        local ok = pcall(LrPasswords.store, CREDENTIAL_KEY, password)
        return ok
    end
    return false
end

-- Returns the stored portal password, or nil if none/inaccessible.
function Api.getStoredPassword()
    local ok, password = pcall(LrPasswords.retrieve, CREDENTIAL_KEY)
    if ok and password and password ~= "" then return password end
    return nil
end

-- Moves a password left in plaintext by older plugin versions into the
-- protected credential store, then removes the plaintext copy. The plaintext
-- is only cleared after it was stored successfully.
function Api.migrateLegacyPassword()
    local prefs = LrPrefs.prefsForPlugin()
    if prefs.apiPass and prefs.apiPass ~= "" then
        if Api.storePassword(prefs.apiPass) then
            prefs.apiPass = nil
        end
    end
end

-- Extracts a cookie value from the LrHttp response headers. The backend keeps
-- the JWT exclusively in the httpOnly `rp_jwt` cookie (see
-- Controller::respondWithToken), so this is the effective token source.
local function extractCookieToken(resHeaders, cookieName)
    if not resHeaders then return nil end
    for _, header in ipairs(resHeaders) do
        if type(header) == "table" and header.field and string.lower(header.field) == "set-cookie" then
            if header.value then
                local match = string.match(header.value, cookieName .. "=([^;]+)")
                if match then return match end
            end
        end
    end
    return nil
end

local function decodeBody(resBody)
    if resBody and resBody ~= "" then
        local success, parsed = pcall(json.decode, resBody)
        if success then return parsed end
    end
    return nil
end

-- Returns data, status, resBody, resHeaders. `status` is 0 when no HTTP
-- response was received (network error).
function Api.call(endpoint, method, payload, jwt)
    if isAuthSession(jwt) then
        return Api.callWithSession(jwt, endpoint, method, payload)
    end

    local headers = {}
    table.insert(headers, { field = "Referer", value = Api.baseUrl })
    if jwt then table.insert(headers, { field = "Authorization", value = "Bearer " .. jwt }) end
    local payloadStr = ""
    if payload then
        table.insert(headers, { field = "Content-Type", value = "application/json" })
        payloadStr = json.encode(payload)
    end
    -- Added once, outside the retry loop, so retries do not duplicate the header.
    if method == "PUT" or method == "DELETE" then
        table.insert(headers, { field = "X-HTTP-Method-Override", value = method })
    end

    local fullUrl = Api.baseUrl .. endpoint
    -- Only idempotent methods may be retried: replaying a POST could create
    -- duplicate galleries or invites.
    local isIdempotent = (method == "GET" or method == "HEAD" or method == "PUT" or method == "DELETE")

    local resBody, resHeaders
    for retry = 0, 1 do
        if method == "GET" or method == "HEAD" then
            resBody, resHeaders = LrHttp.get(fullUrl, headers, REQUEST_TIMEOUT)
        else
            resBody, resHeaders = LrHttp.post(fullUrl, payloadStr, headers, nil, REQUEST_TIMEOUT)
        end

        local status = resHeaders and resHeaders.status or 0
        local isServerError = (status >= 500) or (resHeaders and resHeaders.error)
        if retry == 0 and isServerError and isIdempotent then
            LrTasks.sleep(2)
        else
            break
        end
    end

    local status = resHeaders and resHeaders.status or 0
    return decodeBody(resBody), status, resBody, resHeaders
end

function Api.login(email, password)
    local prefs = LrPrefs.prefsForPlugin()
    email = email or prefs.apiUser
    -- Fall back to the protected store so a saved login can run silently.
    if not password or password == "" then
        password = Api.getStoredPassword()
    end
    if not email or email == "" or not password or password == "" then
        return nil, "Keine Zugangsdaten eingegeben.", ""
    end
    Api.setBaseUrl(prefs.useLocal and "http://localhost:4321" or "https://portal.reisinger.pictures")
    local payload = { email = email, password = password }

    local data, status, resBody, resHeaders = Api.call("/api/auth/login", "POST", payload, nil)

    if status == 200 then
        -- The backend never returns the JWT in the body; it is only set as the
        -- httpOnly `rp_jwt` cookie.
        local token = extractCookieToken(resHeaders, "rp_jwt")
        if token then return token, nil, nil end

        local detail = "Status: 200\nURL: " .. Api.baseUrl .. "/api/auth/login\n"
            .. "Der Server hat kein rp_jwt-Cookie gesetzt."
        return nil, "Sitzung konnte nicht gelesen werden.", detail
    end

    local err = (data and data.error) or "Unbekannter API Fehler"
    local detail = "Status: " .. tostring(status) .. "\nURL: " .. Api.baseUrl .. "/api/auth/login\n"
    if resBody and resBody ~= "" then detail = detail .. "Body: " .. string.sub(resBody, 1, 300) end
    if resHeaders and resHeaders.error then detail = detail .. "\nCurl Error: " .. tostring(resHeaders.error.localizedMessage) end

    return nil, err, detail
end

-- Returns resBody, status on success; nil, status, errDetail on failure.
function Api.uploadMultipart(endpoint, formFields, jwt)
    if isAuthSession(jwt) then
        return Api.uploadWithSession(jwt, endpoint, formFields)
    end

    local fullUrl = Api.baseUrl .. endpoint
    local headers = { { field = "Authorization", value = "Bearer " .. jwt } }

    local function doUpload()
        local resBody, resHeaders = LrHttp.postMultipart(fullUrl, formFields, headers, UPLOAD_TIMEOUT)
        local status = resHeaders and resHeaders.status or 0
        return resBody, status, resHeaders
    end

    local resBody, status, resHeaders = doUpload()
    if status >= 500 or (resHeaders and resHeaders.error) then
        LrTasks.sleep(2)
        resBody, status, resHeaders = doUpload()
    end

    if status >= 200 and status < 300 then
        return resBody, status
    end

    local errDetail = resHeaders and resHeaders.error and resHeaders.error.localizedMessage
        or (resBody and resBody ~= "" and resBody)
        or ("HTTP " .. tostring(status))
    return nil, status, errDetail
end

-- Creates a deliberately small, credential-free session holder.  The JWT is
-- mutable, but no password is retained here; the optional refresh callback
-- can use the protected credential store without exposing it to the holder.
function Api.createSession(jwt, email, refresh)
    return {
        _authSession = true,
        jwt = jwt,
        email = email or "",
        refresh = refresh,
        expired = false,
        refreshing = false
    }
end

-- Performs one silent re-authentication.  The caller (callWithSession or
-- uploadWithSession) owns the one-retry bound; this function never loops.
function Api.refreshSession(session)
    if not isAuthSession(session) then return nil, "Keine aktive Sitzung." end
    if session.expired then return nil, "Sitzung ist abgelaufen." end
    if session.refreshing then return nil, "Sitzung wird bereits erneuert." end

    session.refreshing = true
    local ok, newJwt, err
    if type(session.refresh) == "function" then
        ok, newJwt, err = pcall(session.refresh)
    else
        ok, newJwt, err = pcall(Api.login, session.email)
    end
    session.refreshing = false

    if not ok then
        session.expired = true
        return nil, tostring(newJwt)
    end

    if type(newJwt) == "string" and newJwt ~= "" then
        session.jwt = newJwt
        session.expired = false
        return newJwt, nil
    end

    session.expired = true
    return nil, err or "Sitzung konnte nicht erneuert werden."
end

-- Calls an authenticated endpoint and retries exactly once after a bounded
-- re-authentication.  A 401 is returned before the controller performs the
-- mutation, so the one replay is safe; the original response is returned when
-- renewal is unavailable, and a second 401 marks this session terminally
-- expired.
function Api.callWithSession(session, endpoint, method, payload)
    if not isAuthSession(session) then return Api.call(endpoint, method, payload, session) end
    if session.expired then return nil, 401, "Sitzung ist abgelaufen.", nil end

    local data, status, resBody, resHeaders
    for retry = 0, MAX_AUTH_RETRIES do
        data, status, resBody, resHeaders = Api.call(endpoint, method, payload, session.jwt)
        if status ~= 401 or retry >= MAX_AUTH_RETRIES then break end

        local newJwt = Api.refreshSession(session)
        if not newJwt then return data, status, resBody, resHeaders end
    end
    if status == 401 then session.expired = true end
    return data, status, resBody, resHeaders
end

-- Multipart equivalent of Api.callWithSession.  Upload retries remain
-- idempotent because ManagerCore always sends replace=1.
function Api.uploadWithSession(session, endpoint, formFields)
    if not isAuthSession(session) then return Api.uploadMultipart(endpoint, formFields, session) end
    if session.expired then return nil, 401, "Sitzung ist abgelaufen." end

    local resBody, status, errDetail
    for retry = 0, MAX_AUTH_RETRIES do
        resBody, status, errDetail = Api.uploadMultipart(endpoint, formFields, session.jwt)
        if status ~= 401 or retry >= MAX_AUTH_RETRIES then break end

        local newJwt = Api.refreshSession(session)
        if not newJwt then return nil, status, errDetail end
    end
    if status == 401 then session.expired = true end
    return resBody, status, errDetail
end

-- Returns isAllowed, data, status. A non-200 status is a transient/technical
-- failure, not a role denial; callers must distinguish the two.
function Api.checkRole(jwt)
    local data, status = Api.call("/api/auth/me", "GET", nil, jwt)
    if status == 200 and data then
        if data.is_photographer or data.is_admin or data.is_super_admin then return true, data, status end
    end
    return false, data, status
end

return Api
