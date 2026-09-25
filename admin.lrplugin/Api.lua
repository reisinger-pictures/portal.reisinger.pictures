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
Api.REQUEST_TIMEOUT = REQUEST_TIMEOUT
Api.UPLOAD_TIMEOUT = UPLOAD_TIMEOUT

-- A single request may re-authenticate and retry at most once.  The bound is
-- deliberately per request, not per manager: a fresh token can itself expire
-- during a very long export, while a failed request must never loop.
local MAX_AUTH_RETRIES = 1
Api.MAX_AUTH_RETRIES = MAX_AUTH_RETRIES
-- Role discovery gets one additional bounded attempt after a transient
-- transport/server response. Api.call already retries idempotent requests
-- once; this bound covers the separate auth/me decision made by ManagerCore.
local MAX_ROLE_RETRIES = 1
Api.MAX_ROLE_RETRIES = MAX_ROLE_RETRIES
-- Kept as a public compatibility alias for older plug-in test harnesses.
Api.MAX_SESSION_REFRESHES = MAX_AUTH_RETRIES

local function normalizedMethod(method)
    return string.upper(tostring(method or "GET"))
end

local function isIdempotentMethod(method)
    method = normalizedMethod(method)
    return method == "GET" or method == "HEAD" or method == "PUT" or method == "DELETE"
end

function Api.isTransientStatus(status)
    status = tonumber(status) or 0
    return status == 0 or status == 408 or status == 425 or status == 429 or status >= 500
end

-- The upload endpoint is safe to replay only when the client supplies the
-- server's replacement identity. This keeps the generic HTTP helper from
-- repeating a future/non-idempotent POST merely because it uses multipart.
function Api.isIdempotentUpload(formFields)
    local hasReplace = false
    local hasUuid = false
    for _, field in ipairs(formFields or {}) do
        if type(field) == "table" and field.name == "replace" and tostring(field.value) == "1" then
            hasReplace = true
        elseif type(field) == "table" and field.name == "lr_uuid" and field.value ~= nil and tostring(field.value) ~= "" then
            hasUuid = true
        end
    end
    return hasReplace and hasUuid
end

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
    if type(password) ~= "string" or password == "" then
        return false
    end
    local ok, result = pcall(LrPasswords.store, CREDENTIAL_KEY, password)
    return ok and result ~= false
end

-- Returns the stored portal password, or nil if none/inaccessible.
function Api.getStoredPassword()
    local ok, password = pcall(LrPasswords.retrieve, CREDENTIAL_KEY)
    if ok and type(password) == "string" and password ~= "" then return password end
    return nil
end

-- Moves a password left in plaintext by older plug-in versions into the
-- protected credential store, then removes the plaintext copy. The plaintext
-- is only cleared after it was stored successfully. The boolean result lets
-- callers surface a keychain failure instead of silently losing persistence.
function Api.migrateLegacyPassword()
    local prefs = LrPrefs.prefsForPlugin()
    local legacyPassword = prefs.apiPass
    if type(legacyPassword) ~= "string" or legacyPassword == "" then
        return true
    end
    if Api.storePassword(legacyPassword) then
        prefs.apiPass = nil
        return true
    end
    return false
end

-- Extracts a cookie value from the LrHttp response headers. The backend keeps
-- the JWT exclusively in the httpOnly `rp_jwt` cookie (see
-- Controller::respondWithToken), so this is the effective token source.
-- parseCookie is the SDK-supported parser and correctly handles URL encoding
-- and attributes; the small pattern fallback keeps the mock harness useful on
-- SDK-compatible runtimes that do not expose that helper.
local function trimCookiePart(value)
    return (string.gsub(value, "^%s*(.-)%s*$", "%1"))
end

local function extractCookieToken(resHeaders, cookieName)
    if not resHeaders then return nil end
    for _, header in ipairs(resHeaders) do
        if type(header) == "table" and header.field and string.lower(header.field) == "set-cookie" then
            local cookieValue = header.value
            if type(cookieValue) == "string" then
                if type(LrHttp.parseCookie) == "function" then
                    local ok, parsed = pcall(LrHttp.parseCookie, cookieValue)
                    if ok and type(parsed) == "table" then
                        local token = parsed[cookieName]
                        if type(token) == "string" and token ~= "" then return token end
                    end
                end

                -- LrHttp.parseCookie is available in Lightroom, but keep a
                -- Lua-5.1-compatible fallback for SDK-compatible test hosts.
                -- Lua patterns have no alternation or '+' quantifier, so split
                -- the Set-Cookie value explicitly instead of using a regex.
                for part in string.gmatch(cookieValue .. ";", "[^;,]*") do
                    local name, value = string.match(part, "^([^=]*)=(.*)$")
                    if name and trimCookiePart(name) == cookieName then
                        value = trimCookiePart(value or "")
                        if string.sub(value, 1, 1) == '"' and string.sub(value, -1) == '"' then
                            value = string.sub(value, 2, -2)
                        end
                        if value ~= "" then return value end
                    end
                end
            end
        end
    end
    return nil
end

local function appendErrorPart(parts, label, value)
    if value ~= nil and tostring(value) ~= "" then
        table.insert(parts, tostring(label) .. ": " .. tostring(value))
    end
end

-- Returns a bounded, user-displayable error detail. LrHttp documents
-- errorCode/name/nativeCode (not localizedMessage) in its network-error
-- object; keeping this conversion in one place prevents callers from losing
-- timeout/certificate/connection details.
function Api.getErrorDetail(data, status, resBody, resHeaders)
    local parts = {}
    if type(resHeaders) == "table" and type(resHeaders.error) == "table" then
        local errorInfo = resHeaders.error
        appendErrorPart(parts, "Fehler", errorInfo.errorCode)
        appendErrorPart(parts, "Meldung", errorInfo.name)
        appendErrorPart(parts, "Systemcode", errorInfo.nativeCode)
        -- Keep compatibility with older SDK builds that exposed this field.
        appendErrorPart(parts, "Meldung", errorInfo.localizedMessage)
    end
    if #parts > 0 then return table.concat(parts, "; ") end

    if type(data) == "table" and data.error ~= nil and tostring(data.error) ~= "" then
        return tostring(data.error)
    end
    if type(resBody) == "string" and resBody ~= "" then
        return string.sub(resBody, 1, 300)
    end
    if tonumber(status) == 0 then
        return "Keine HTTP-Antwort (Netzwerkfehler oder Timeout)."
    end
    return "HTTP " .. tostring(status or 0)
end

local function decodeBody(resBody)
    if resBody and resBody ~= "" then
        local success, parsed = pcall(json.decode, resBody)
        if success then return parsed end
    end
    return nil
end

-- Returns data, status, resBody, resHeaders, errorDetail. `status` is 0 when
-- no HTTP response was received (network error).
function Api.call(endpoint, method, payload, jwt)
    if isAuthSession(jwt) then
        return Api.callWithSession(jwt, endpoint, method, payload)
    end

    method = normalizedMethod(method)
    local headers = {}
    table.insert(headers, { field = "Referer", value = Api.baseUrl })
    if jwt then table.insert(headers, { field = "Authorization", value = "Bearer " .. jwt }) end
    local payloadStr = ""
    if payload ~= nil then
        table.insert(headers, { field = "Content-Type", value = "application/json" })
        payloadStr = json.encode(payload)
    end

    local fullUrl = Api.baseUrl .. endpoint
    -- Only idempotent methods may be retried: replaying a POST could create
    -- duplicate galleries or invites. PUT/DELETE use LrHttp's native method
    -- argument, so no method-override header is needed.
    local isIdempotent = isIdempotentMethod(method)

    local resBody, resHeaders
    for retry = 0, 1 do
        if method == "GET" or method == "HEAD" then
            resBody, resHeaders = LrHttp.get(fullUrl, headers, REQUEST_TIMEOUT)
        else
            local httpMethod = (method == "PUT" or method == "DELETE") and method or "POST"
            resBody, resHeaders = LrHttp.post(fullUrl, payloadStr, headers, httpMethod, REQUEST_TIMEOUT)
        end

        local status = tonumber(resHeaders and resHeaders.status) or 0
        local isTransient = Api.isTransientStatus(status) or (resHeaders and resHeaders.error)
        if retry == 0 and isTransient and isIdempotent then
            LrTasks.sleep(2)
        else
            break
        end
    end

    local status = tonumber(resHeaders and resHeaders.status) or 0
    local data = decodeBody(resBody)
    return data, status, resBody, resHeaders, Api.getErrorDetail(data, status, resBody, resHeaders)
end

function Api.login(email, password)
    local prefs = LrPrefs.prefsForPlugin()
    email = email or prefs.apiUser
    -- Fall back to the protected store so a saved login can run silently.
    if not password or password == "" then
        password = Api.getStoredPassword()
    end
    if type(email) ~= "string" or email == "" or type(password) ~= "string" or password == "" then
        return nil, "Keine Zugangsdaten eingegeben.", ""
    end
    Api.setBaseUrl(prefs.useLocal and "http://localhost:4321" or "https://portal.reisinger.pictures")
    local payload = { email = email, password = password }

    local data, status, resBody, resHeaders, errorDetail = Api.call("/api/auth/login", "POST", payload, nil)

    if status == 200 then
        -- The backend never returns the JWT in the body; it is only set as the
        -- httpOnly `rp_jwt` cookie. Deliberately do not accept a body token:
        -- accepting a legacy/foreign field would bypass the cookie contract.
        local token = extractCookieToken(resHeaders, "rp_jwt")
        if token then return token, nil, nil end

        local detail = "Status: 200\nURL: " .. Api.baseUrl .. "/api/auth/login\n"
            .. "Der Server hat kein rp_jwt-Cookie gesetzt."
        return nil, "Sitzung konnte nicht gelesen werden.", detail
    end

    local err = (type(data) == "table" and data.error) or "Unbekannter API-Fehler"
    local detail = "Status: " .. tostring(status) .. "\nURL: " .. Api.baseUrl .. "/api/auth/login"
    if errorDetail and errorDetail ~= "" then detail = detail .. "\n" .. tostring(errorDetail) end
    if resBody and resBody ~= "" and (not errorDetail or errorDetail == "") then
        detail = detail .. "\nBody: " .. string.sub(resBody, 1, 300)
    end

    return nil, err, detail
end

-- Returns resBody, status on success; nil, status, errDetail on failure.
-- Multipart requests are POSTs, so transport retries are enabled only for the
-- replace=1 + lr_uuid contract used by the management uploader.
function Api.uploadMultipart(endpoint, formFields, jwt)
    if isAuthSession(jwt) then
        return Api.uploadWithSession(jwt, endpoint, formFields)
    end

    local fullUrl = Api.baseUrl .. endpoint
    local headers = {
        { field = "Referer", value = Api.baseUrl },
        { field = "Authorization", value = "Bearer " .. jwt }
    }
    local allowRetry = Api.isIdempotentUpload(formFields)

    local function doUpload()
        local resBody, resHeaders = LrHttp.postMultipart(fullUrl, formFields, headers, UPLOAD_TIMEOUT)
        local status = tonumber(resHeaders and resHeaders.status) or 0
        return resBody, status, resHeaders
    end

    local resBody, status, resHeaders = doUpload()
    if allowRetry and (Api.isTransientStatus(status) or (resHeaders and resHeaders.error)) then
        LrTasks.sleep(2)
        resBody, status, resHeaders = doUpload()
    end

    if status >= 200 and status < 300 then
        return resBody, status
    end

    return nil, status, Api.getErrorDetail(nil, status, resBody, resHeaders)
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
-- LrTasks.pcall is required because Api.login yields while LrHttp is running;
-- the standard Lua pcall cannot safely cross that yield in Lua 5.1.
function Api.refreshSession(session)
    if not isAuthSession(session) then return nil, "Keine aktive Sitzung." end
    if session.expired then return nil, "Sitzung ist abgelaufen." end
    if session.refreshing then return nil, "Sitzung wird bereits erneuert." end

    session.refreshing = true
    local ok, newJwt, err
    if type(session.refresh) == "function" then
        ok, newJwt, err = LrTasks.pcall(session.refresh)
    else
        ok, newJwt, err = LrTasks.pcall(Api.login, session.email)
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
-- re-authentication. Only idempotent methods are replayed after 401; a POST is
-- allowed to reach the server once and then requires explicit re-auth rather
-- than risking a duplicate gallery/invite.
function Api.callWithSession(session, endpoint, method, payload)
    if not isAuthSession(session) then return Api.call(endpoint, method, payload, session) end
    method = normalizedMethod(method)
    if session.expired then return nil, 401, nil, nil, "Sitzung ist abgelaufen." end

    local data, status, resBody, resHeaders, errorDetail
    local allowAuthRetry = isIdempotentMethod(method)
    for retry = 0, MAX_AUTH_RETRIES do
        data, status, resBody, resHeaders, errorDetail = Api.call(endpoint, method, payload, session.jwt)
        if status ~= 401 or retry >= MAX_AUTH_RETRIES or not allowAuthRetry then break end

        local newJwt, refreshError = Api.refreshSession(session)
        if not newJwt then
            session.expired = true
            return data, status, resBody, resHeaders, refreshError or errorDetail
        end
    end
    if status == 401 then session.expired = true end
    return data, status, resBody, resHeaders, errorDetail
end

-- Multipart equivalent of Api.callWithSession. The caller must provide the
-- replace=1 + lr_uuid identity before either a transport or 401 replay is
-- allowed; otherwise the upload is attempted once only.
function Api.uploadWithSession(session, endpoint, formFields)
    if not isAuthSession(session) then return Api.uploadMultipart(endpoint, formFields, session) end
    if session.expired then return nil, 401, "Sitzung ist abgelaufen." end

    local resBody, status, errDetail
    local allowAuthRetry = Api.isIdempotentUpload(formFields)
    for retry = 0, MAX_AUTH_RETRIES do
        resBody, status, errDetail = Api.uploadMultipart(endpoint, formFields, session.jwt)
        if status ~= 401 or retry >= MAX_AUTH_RETRIES or not allowAuthRetry then break end

        local newJwt, refreshError = Api.refreshSession(session)
        if not newJwt then
            session.expired = true
            return nil, status, refreshError or errDetail
        end
    end
    if status == 401 then session.expired = true end
    return resBody, status, errDetail
end

-- Returns isAllowed, data, status, errorDetail. A non-200 status is a
-- transient/technical failure, not a role denial; callers must distinguish the
-- two. Role discovery gets one bounded retry for transport/server failures.
function Api.checkRole(jwt)
    local lastData, lastStatus, lastBody, lastHeaders, lastDetail
    for attempt = 0, MAX_ROLE_RETRIES do
        lastData, lastStatus, lastBody, lastHeaders, lastDetail = Api.call("/api/auth/me", "GET", nil, jwt)
        if lastStatus == 200 then
            if type(lastData) == "table"
                and (lastData.is_photographer or lastData.is_admin or lastData.is_super_admin) then
                return true, lastData, lastStatus, nil
            end
            return false, lastData, lastStatus, lastDetail
        end
        if not Api.isTransientStatus(lastStatus) or attempt >= MAX_ROLE_RETRIES then
            return false, lastData, lastStatus, lastDetail
        end
        LrTasks.sleep(1)
    end
    return false, lastData, lastStatus, lastDetail
end

return Api
