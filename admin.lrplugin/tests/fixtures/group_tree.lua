-- A tree-shaped fixture with a deliberate back-edge.  The plugin must treat
-- malformed data as display-only input and never recurse forever.
local root = { id = "root", name = "Root" }
local child = { id = "child", name = "Child" }
local grandchild = { id = "grandchild", name = "Grandchild" }
local sibling = { id = "sibling", name = "Sibling" }

root.children = { child }
child.children = { grandchild }
grandchild.children = { root }

return {
    groups = { root, sibling }
}
