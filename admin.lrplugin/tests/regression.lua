local Utils = dofile("Utils.lua")
local json = dofile("json.lua")
local fixture = dofile("tests/fixtures/group_tree.lua")

local function values(items)
    local result = {}
    for _, item in ipairs(items) do
        table.insert(result, item.value)
    end
    return table.concat(result, ",")
end

-- Excluding the edited root removes its entire descendant subtree, while an
-- unrelated sibling remains a normal parent choice.
local rootChoices = Utils.flattenGroupChoices(fixture.groups, "root")
assert(values(rootChoices) == "sibling", "edited group descendants must be filtered")
assert(not Utils.isGroupParentSelectionValid(fixture.groups, "root", "child"), "descendant parents must be rejected")
assert(not Utils.isGroupParentSelectionValid(fixture.groups, "root", "unknown"), "unknown parents must be rejected")
assert(Utils.isGroupParentSelectionValid(fixture.groups, "root", "sibling"), "unrelated parents must remain selectable")
assert(Utils.isGroupParentSelectionValid(fixture.groups, nil, ""), "root selection must be valid")

-- A flat API response is handled as well: parent_id links are used even when
-- the response omits the nested children array.
local flatRoot = { id = "flat-root", name = "Flat root", parent_id = nil }
local flatChild = { id = "flat-child", name = "Flat child", parent_id = "flat-root" }
local flatSibling = { id = "flat-sibling", name = "Flat sibling", parent_id = nil }
local flatChoices = Utils.flattenGroupChoices({ flatRoot, flatChild, flatSibling }, "flat-root")
assert(values(flatChoices) == "flat-sibling", "flat descendant parents must be filtered")
assert(not Utils.isGroupParentSelectionValid({ flatRoot, flatChild, flatSibling }, "flat-root", "flat-child"), "flat descendant validation failed")

-- Excluding the sibling preserves the root and its normal child choices.  The
-- fixture's back-edge is cut off by the visited set.
local siblingChoices = Utils.flattenGroupChoices(fixture.groups, "sibling")
assert(values(siblingChoices) == "root,child,grandchild", "normal child choices must remain")
assert(#Utils.flattenGalleries(fixture) == 1, "cyclic group tree must not recurse forever while flattening galleries")

-- Lua cannot represent a JSON null as a table field value.  The sentinel keeps
-- the key present when a gallery edit clears its expiry.
assert(json.encode({ expires_at = json.null }) == '{"expires_at":null}', "explicit JSON null serialization failed")
assert(json.encode({ expires_at = "2026-12-31" }) == '{"expires_at":"2026-12-31"}', "expiry value serialization failed")

print("Lua Lightroom regression checks passed")
