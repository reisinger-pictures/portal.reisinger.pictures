-- INFRA-12 regression for the legacy plaintext password migration.
--
-- The normal Lightroom `import 'LrXxx'` syntax is rewritten only in this test
-- source so the production file stays Lua 5.1 compatible. The keychain is
-- mocked so the test can simulate "store fails, then later recovers".
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

-- A single shared prefs table models the persistent LrPrefs store. A fresh
-- table per call would hide the plaintext that this regression must observe.
local prefs = {
    apiUser = "photographer@example.test",
    useLocal = false,
    apiPass = "legacy-plaintext",
}
local keychain = {}
local storeSucceeds = false
local storeCalls = 0

_G.LrPrefs = { prefsForPlugin = function() return prefs end }
_G.LrTasks = { pcall = pcall, sleep = function(_) end }
_G.LrPasswords = {
    store = function(_, value)
        storeCalls = storeCalls + 1
        if storeSucceeds then
            keychain.portalPassword = value
            return true
        end
        return false
    end,
    retrieve = function(_) return keychain.portalPassword end,
}

local Api = loadApi()

-- First attempt: keychain unavailable. The plaintext must be retained and must
-- not become readable through the keychain-only accessor.
assert(Api.migrateLegacyPassword() == false, "a failing keychain store must report failure")
assert(prefs.apiPass == "legacy-plaintext", "plaintext must be retained until migration succeeds")
assert(Api.getStoredPassword() == nil, "retained plaintext must not leak through getStoredPassword")

-- A later successful login retries the migration (ManagerCore calls this after
-- a successful login). Now the keychain works and the plaintext is cleared.
storeSucceeds = true
assert(Api.migrateLegacyPassword() == true, "a recovered keychain must complete the migration")
assert(prefs.apiPass == nil, "plaintext must be removed after a successful migration")
assert(Api.getStoredPassword() == "legacy-plaintext", "the migrated password must be in the keychain")

-- A NEW password is written only to the keychain, never to LrPrefs.
assert(Api.storePassword("brand-new-password") == true, "storing a new password failed")
assert(prefs.apiPass == nil, "a new password must never be written into LrPrefs")
assert(Api.getStoredPassword() == "brand-new-password", "the new password must come from the keychain")

-- A subsequent migration call (e.g. the next successful login) is a no-op.
local callsBeforeNoop = storeCalls
assert(Api.migrateLegacyPassword() == true, "a migrated plugin must report success")
assert(storeCalls == callsBeforeNoop, "a migrated plugin must not re-store anything")

print("Lua legacy password migration regression checks passed")
