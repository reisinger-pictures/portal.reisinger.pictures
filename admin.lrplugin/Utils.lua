local Utils = {}

function Utils.slugify(text)
    if not text then return "" end
    local s = text
    s = s:gsub("Ä", "ae"):gsub("ä", "ae")
    s = s:gsub("Ö", "oe"):gsub("ö", "oe")
    s = s:gsub("Ü", "ue"):gsub("ü", "ue")
    s = s:gsub("ß", "ss")
    s = string.lower(s)
    s = s:gsub("[^a-z0-9]+", "-")
    s = s:gsub("^-+", ""):gsub("-+$", "")
    return s
end

-- Flattens a group tree while protecting the dialog from malformed cycles.
-- excludedIds contains the edited group; its entire subtree is skipped, so a
-- group can never be offered as its own parent or as a parent of one of its
-- descendants.  The optional arguments keep the old flattenGroups(groups)
-- calling convention intact.
function Utils.flattenGroups(groups, depth, options)
    local flat = {}
    depth = depth or 0
    options = options or {}
    local excludedIds = options.excludeIds or {}
    local visited = options.visited or {}
    local prefix = string.rep("--", depth) .. (depth > 0 and "> " or "")

    if groups then
        for _, group in ipairs(groups) do
            local id = group.id ~= nil and tostring(group.id) or tostring(group)
            if not visited[id] then
                visited[id] = true
                if not excludedIds[id] then
                    table.insert(flat, { title = prefix .. group.name, value = group.id, raw = group })
                    if group.children then
                        local childrenFlat = Utils.flattenGroups(group.children, depth + 1, {
                            excludeIds = excludedIds,
                            visited = visited
                        })
                        for _, childItem in ipairs(childrenFlat) do
                            table.insert(flat, childItem)
                        end
                    end
                end
            end
        end
    end
    return flat
end

-- Returns the groups that are valid parent choices.  In edit mode the selected
-- group and all descendants are omitted; root and unrelated child groups stay
-- available.  A visited set also makes an already-cyclic response safe to
-- display.
local function collectGroupRelations(groups, inheritedParentId, relations, seen)
    for _, group in ipairs(groups or {}) do
        if type(group) == "table" then
            local id = group.id ~= nil and tostring(group.id) or nil
            if id and not seen[id] then
                seen[id] = true
                local parentId = group.parent_id ~= nil and tostring(group.parent_id) or inheritedParentId
                if parentId then
                    local children = relations[parentId] or {}
                    table.insert(children, id)
                    relations[parentId] = children
                end
                if inheritedParentId and inheritedParentId ~= parentId then
                    -- Keep the nesting edge as well as an explicitly supplied
                    -- parent_id; malformed mixed trees then fail closed.
                    local nestedChildren = relations[inheritedParentId] or {}
                    table.insert(nestedChildren, id)
                    relations[inheritedParentId] = nestedChildren
                end
                collectGroupRelations(group.children, id, relations, seen)
            end
        end
    end
end

function Utils.flattenGroupChoices(groups, editingGroup)
    local editingGroupId = type(editingGroup) == "table" and editingGroup.id or editingGroup
    local excludedIds = {}
    if editingGroupId ~= nil and editingGroupId ~= "" then
        editingGroupId = tostring(editingGroupId)
        local relations = {}
        local seen = {}
        collectGroupRelations(groups, nil, relations, seen)
        if type(editingGroup) == "table" then
            -- The selected object may be a fuller resource than its tree
            -- entry; traverse it independently so its children are still
            -- excluded from the parent choices.
            collectGroupRelations({ editingGroup }, nil, relations, {})
        end

        -- Parent IDs are authoritative when a response is flat; nested
        -- children are folded into the same relation map above.  The visited
        -- set also makes a malformed cycle harmless.
        local queue = { editingGroupId }
        local visited = {}
        while #queue > 0 do
            local current = table.remove(queue, 1)
            if not visited[current] then
                visited[current] = true
                excludedIds[current] = true
                for _, childId in ipairs(relations[current] or {}) do
                    table.insert(queue, childId)
                end
            end
        end
    end
    return Utils.flattenGroups(groups, 0, { excludeIds = excludedIds })
end

-- Validates the value selected in a parent popup before a request is sent.
-- The popup is filtered for normal interaction, but this second check also
-- protects against stale bindings or a tampered property value.  Unknown
-- parents fail closed as well; an empty value explicitly means the root.
function Utils.isGroupParentSelectionValid(groups, editingGroup, parentId)
    if parentId == nil or parentId == "" then return true end

    local editingGroupId = type(editingGroup) == "table" and editingGroup.id or editingGroup
    local choices = Utils.flattenGroupChoices(groups, editingGroup)
    local wanted = tostring(parentId)
    for _, item in ipairs(choices) do
        if tostring(item.value) == wanted then return true end
    end
    return false
end

function Utils.flattenGalleries(tree)
    local flat = {}
    local visitedGroups = {}
    local function processGroup(group, depth)
        local groupId = group.id ~= nil and tostring(group.id) or tostring(group)
        if groupId and visitedGroups[groupId] then return end
        if groupId then visitedGroups[groupId] = true end

        local prefix = string.rep("--", depth) .. (depth > 0 and "> " or "")
        if group.galleries then
            for _, gal in ipairs(group.galleries) do
                local icon = gal.type == 'selection' and "✨ " or "📦 "
                local live = gal.is_live and " (LIVE)" or ""
                table.insert(flat, { title = prefix .. icon .. gal.name .. live, value = gal.id, raw = gal })
            end
        end
        if group.children then
            for _, child in ipairs(group.children) do
                processGroup(child, depth + 1)
            end
        end
    end
    if tree.groups then
        for _, g in ipairs(tree.groups) do processGroup(g, 0) end
    end
    if tree.root_galleries then
        for _, gal in ipairs(tree.root_galleries) do
            local icon = gal.type == 'selection' and "✨ " or "📦 "
            local live = gal.is_live and " (LIVE)" or ""
            table.insert(flat, { title = icon .. gal.name .. live, value = gal.id, raw = gal })
        end
    end
    if #flat == 0 then table.insert(flat, { title = "Keine Galerien vorhanden", value = "" }) end
    return flat
end

return Utils
