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
_G.LrTasks = { sleep = function(_) end }
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

-- Multipart calls use the same bounded renewal path and remain idempotent via
-- the caller's replace=1 form field.
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
})
assert(uploadStatus == 200 and uploadBody == '{"uploaded":true}', "upload renewal did not succeed")
assert(uploadRefreshes == 1, "upload renewal was not bounded")

print("Lua API session regression checks passed")
