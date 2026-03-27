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

function Utils.flattenGroups(groups, depth)
    local flat = {}
    depth = depth or 0
    local prefix = string.rep("--", depth) .. (depth > 0 and "> " or "")
    if groups then
        for _, g in ipairs(groups) do
            table.insert(flat, { title = prefix .. g.name, value = g.id, raw = g })
            if g.children then
                local childrenFlat = Utils.flattenGroups(g.children, depth + 1)
                for _, cf in ipairs(childrenFlat) do
                    table.insert(flat, cf)
                end
            end
        end
    end
    return flat
end

function Utils.flattenGalleries(tree)
    local flat = {}
    local function processGroup(group, depth)
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
