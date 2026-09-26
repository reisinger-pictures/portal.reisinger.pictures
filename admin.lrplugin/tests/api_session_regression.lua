-- Deterministic mock harness for Api.lua's bounded 401 renewal path.
-- The normal Lightroom `import 'LrXxx'` syntax is rewritten only in this
-- test source so the production file remains untouched and Lua 5.1 compatible.
package.path = "./?.lua;" .. package.path

local function readFile(path)
    local file = assert(io.open(path, "rb"))
    local content = file:read("*a")
    file:close()
    return content
end

local function loadApi()
    local source = readFile("Api.lua")
    local imports = {
        { "local LrHttp = import 'LrHttp'", "local LrHttp = _G.LrHttp" },
        { "local LrPrefs = import 'LrPrefs'", "local LrPrefs = _G.LrPrefs" },
        { "local LrTasks = import 'LrTasks'", "local LrTasks = _G.LrTasks" },
        { "local LrPasswords = import 'LrPasswords'", "local LrPasswords = _G.LrPasswords" },
    }
    for _, replacement in ipairs(imports) do
        source = source:gsub(replacement[1], replacement[2], 1)
    end

    if loadstring then
        return assert(loadstring(source, "@Api.lua"))()
    end
    return assert(load(source, "@Api.lua"))()
end

local calls = {}
local responses = {}
local function nextResponse(kind)
    table.insert(calls, kind)
    assert(#responses > 0, "mock response queue exhausted")
    return table.remove(responses, 1)
end

_G.LrPrefs = {
    prefsForPlugin = function()
        return { apiUser = "photographer@example.test", useLocal = false }
    end
}
_G.LrTasks = { pcall = pcall, sleep = function(_) end }
_G.LrPasswords = {
    store = function(_, _) return true end,
    retrieve = function(_) return "protected-password" end,
}
_G.LrHttp = {
    get = function(_, headers)
        local response = nextResponse("get")
        table.insert(calls, headers)
        return response.body, response.headers
    end,
    post = function(_, _, headers)
        local response = nextResponse("post")
        table.insert(calls, headers)
        return response.body, response.headers
    end,
    postMultipart = function(_, _, headers)
        local response = nextResponse("upload")
        table.insert(calls, headers)
        return response.body, response.headers
    end,
}

local Api = loadApi()
assert(Api.MAX_AUTH_RETRIES == 1, "auth retry bound must be exactly one")

local function authHeader(headers)
    for _, header in ipairs(headers) do
        if header.field == "Authorization" then return header.value end
    end
    return nil
end

-- A 401 is renewed once, and the retry uses the replacement token.
responses = {
    { headers = { status = 401 } },
    { body = '{"ok":true}', headers = { status = 200 } },
}
local refreshes = 0
local session = Api.createSession("old-token", "photographer@example.test", function()
    refreshes = refreshes + 1
    return "new-token"
end)
local data, status = Api.callWithSession(session, "/api/management/galleries", "GET", nil)
assert(status == 200 and data.ok == true, "401 renewal did not retry successfully")
assert(refreshes == 1, "401 renewal was not bounded to one attempt")
assert(authHeader(calls[2]) == "Bearer old-token", "first request used the wrong token")
assert(authHeader(calls[4]) == "Bearer new-token", "retry did not use the renewed token")

-- The default refresh path obtains a replacement JWT from the protected
-- credential store/login endpoint when no explicit callback is supplied.
responses = {
    { headers = { status = 401 } },
    { headers = {} },
    { body = '{"ok":true}', headers = { status = 200 } },
}
local cookieHeaders = { status = 200 }
table.insert(cookieHeaders, { field = "Set-Cookie", value = "rp_jwt=cookie-token; Path=/" })
responses[2].headers = cookieHeaders
local protectedSession = Api.createSession("old-token", "photographer@example.test")
local protectedData, protectedStatus = Api.callWithSession(protectedSession, "/protected", "GET", nil)
assert(protectedStatus == 200 and protectedData.ok == true, "protected-store refresh failed")
assert(authHeader(calls[#calls]) == "Bearer cookie-token", "login cookie token was not used")

-- A second 401 marks the session terminal; no later request can loop or call
-- the credential store again.
responses = {
    { headers = { status = 401 } },
    { headers = { status = 401 } },
}
local terminalRefreshes = 0
local terminal = Api.createSession("old-token", "photographer@example.test", function()
    terminalRefreshes = terminalRefreshes + 1
    return "still-invalid"
end)
local _, terminalStatus = Api.callWithSession(terminal, "/api/management/galleries", "GET", nil)
assert(terminalStatus == 401 and terminal.expired, "repeated 401 was not made terminal")
local callCount = #calls
local _, expiredStatus = Api.callWithSession(terminal, "/api/management/galleries", "GET", nil)
assert(expiredStatus == 401 and #calls == callCount, "expired session made another request")
assert(terminalRefreshes == 1, "terminal session refreshed more than once")

-- The bound is per request, so a genuinely long session can renew again after
-- the new token later expires; it still gets only one retry for that request.
responses = {
    { headers = { status = 401 } },
    { headers = { status = 200 } },
    { headers = { status = 401 } },
    { headers = { status = 200 } },
}
local longRefreshes = 0
local longSession = Api.createSession("token-1", "photographer@example.test", function()
    longRefreshes = longRefreshes + 1
    return "token-" .. tostring(longRefreshes + 1)
end)
assert(select(2, Api.callWithSession(longSession, "/first", "GET", nil)) == 200)
assert(select(2, Api.callWithSession(longSession, "/second", "GET", nil)) == 200)
assert(longRefreshes == 2, "per-request auth bound was accidentally global")

-- A non-idempotent POST is attempted once after a 401; replaying it could
-- create a duplicate gallery, group, invite, or rating mutation.
responses = { { headers = { status = 401 } } }
local postRefreshes = 0
local postSession = Api.createSession("post-old", "photographer@example.test", function()
    postRefreshes = postRefreshes + 1
    return "post-new"
end)
local postCallCount = #calls
local _, postStatus = Api.callWithSession(postSession, "/api/management/galleries", "POST", {})
assert(postStatus == 401 and postRefreshes == 0, "non-idempotent POST was replayed")
assert(#calls == postCallCount + 2, "non-idempotent POST made more than one request")

-- Multipart calls use the same bounded renewal path and remain idempotent via
-- the caller's replace=1 + lr_uuid identity contract.
responses = {
    { headers = { status = 401 } },
    { body = '{"uploaded":true}', headers = { status = 200 } },
}
local uploadRefreshes = 0
local uploadSession = Api.createSession("upload-old", "photographer@example.test", function()
    uploadRefreshes = uploadRefreshes + 1
    return "upload-new"
end)
local uploadBody, uploadStatus = Api.uploadWithSession(uploadSession, "/api/management/upload", {
    { name = "replace", value = "1" },
    { name = "lr_uuid", value = "uuid-1" },
})
assert(uploadStatus == 200 and uploadBody == '{"uploaded":true}', "upload renewal did not succeed")
assert(uploadRefreshes == 1, "upload renewal was not bounded")

assert(Api.isIdempotentUpload({
    { name = "replace", value = "1" },
    { name = "lr_uuid", value = "uuid-2" },
}), "replace + UUID upload must be replayable")
assert(not Api.isIdempotentUpload({ { name = "replace", value = "1" } }),
    "replace without UUID must not be replayable")

-- Without the replacement identity, a 401 upload is not replayed even when a
-- refresh callback is available.
responses = { { headers = { status = 401 } } }
local unsafeUploadRefreshes = 0
local unsafeUploadSession = Api.createSession("unsafe-upload-old", "photographer@example.test", function()
    unsafeUploadRefreshes = unsafeUploadRefreshes + 1
    return "unsafe-upload-new"
end)
local unsafeCallCount = #calls
local _, unsafeUploadStatus = Api.uploadWithSession(unsafeUploadSession, "/api/management/upload", {
    { name = "replace", value = "1" },
})
assert(unsafeUploadStatus == 401 and unsafeUploadRefreshes == 0,
    "upload without UUID identity was replayed")
assert(#calls == unsafeCallCount + 2, "unsafe upload made more than one request")

-- INFRA-7: a refresh that fails for a transport reason (timeout, 5xx,
-- network, status 0) must NOT mark the session terminal. A later request
-- retries the refresh and can recover.
responses = {
    { headers = { status = 401 } }, -- first protected request
    { headers = { status = 401 } }, -- second protected request
    { body = '{"ok":true}', headers = { status = 200 } }, -- retry after re-login
}
local transientRefreshAttempts = 0
local transient = Api.createSession("transient-old", "photographer@example.test", function()
    transientRefreshAttempts = transientRefreshAttempts + 1
    if transientRefreshAttempts == 1 then
        return nil, "Netzwerkfehler", "Status: 0", 0
    end
    return "transient-new"
end)
local _, transientStatus = Api.callWithSession(transient, "/first", "GET", nil)
assert(transientStatus == 401, "a transport refresh failure must surface the original 401")
assert(not transient.expired, "a transport refresh failure must NOT expire the session")
assert(transientRefreshAttempts == 1, "refresh must be attempted once per request")

local _, recoveredStatus = Api.callWithSession(transient, "/second", "GET", nil)
assert(recoveredStatus == 200, "a later request did not recover after a transport-only refresh failure")
assert(transientRefreshAttempts == 2, "a later request must retry the refresh")
assert(not transient.expired, "a recovered session must stay usable")

-- A definitive auth rejection from the refresh callback is terminal and no
-- later request may reach the network.
responses = { { headers = { status = 401 } } }
local rejectedRefreshAttempts = 0
local rejected = Api.createSession("rejected-old", "photographer@example.test", function()
    rejectedRefreshAttempts = rejectedRefreshAttempts + 1
    return nil, "Ungueltige Sitzung", "Status: 401", 401
end)
local _, rejectedStatus = Api.callWithSession(rejected, "/first", "GET", nil)
assert(rejectedStatus == 401 and rejected.expired, "a 401 refresh rejection must be terminal")
local rejectedCallCount = #calls
local _, rejectedAgain = Api.callWithSession(rejected, "/second", "GET", nil)
assert(rejectedAgain == 401 and #calls == rejectedCallCount,
    "an expired session must not make another request")
assert(rejectedRefreshAttempts == 1, "a terminal session must not refresh again")

-- The default Api.login refresh path carries the HTTP status as its fourth
-- value, so a 401 login rejection is terminal there too.
responses = {
    { headers = { status = 401 } },
    { body = '{"error":"invalid"}', headers = { status = 401 } },
}
local defaultRejected = Api.createSession("default-rejected", "photographer@example.test")
local _, defaultRejectedStatus = Api.callWithSession(defaultRejected, "/protected", "GET", nil)
assert(defaultRejectedStatus == 401 and defaultRejected.expired,
    "a 401 login refresh failure must be terminal on the default path")

print("Lua API session regression checks passed")
