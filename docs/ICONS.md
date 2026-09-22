# Icon legend

Every QuickNav result carries a small leading icon that tells you, at a glance, what kind of
record you're looking at — before you even read the label. Products get a real thumbnail instead
of an icon whenever one is available; everything else uses one of the [Lucide](https://lucide.dev)
icons below, colored per type so the same shape always means the same thing throughout the widget
(the live results list, the personalization tiles, and the type-filter chips all share this exact
mapping).

Three screenshots below show one example row per type, so you can match a shape you're looking at
directly against its meaning.

![Icon legend, part 1](screenshots/03-icon-legend-1.png)
![Icon legend, part 2](screenshots/03-icon-legend-2.png)
![Icon legend, part 3](screenshots/03-icon-legend-3.png)

## Full reference table

| Icon | Icon (Lucide name) | Entity type | What it means |
|---|---|---|---|
| <img src="icons/package.svg" width="16" height="16" alt="package icon"> | `package` | Product | A catalog product |
| <img src="icons/folder-tree.svg" width="16" height="16" alt="folder-tree icon"> | `folder-tree` | Category | A catalog category |
| <img src="icons/settings.svg" width="16" height="16" alt="settings icon"> | `settings` | Setting | A `system.xml` configuration **section** |
| <img src="icons/sliders-horizontal.svg" width="16" height="16" alt="sliders-horizontal icon"> | `sliders-horizontal` | Setting Group | A configuration **group** inside a section |
| <img src="icons/tag.svg" width="16" height="16" alt="tag icon"> | `tag` | Setting Field | A single configuration **field** |
| <img src="icons/menu.svg" width="16" height="16" alt="menu icon"> | `menu` | Menu Item | An admin menu entry (matched by its own label, so you can jump straight to a menu item without knowing which module owns it) |
| <img src="icons/user.svg" width="16" height="16" alt="user icon"> | `user` | Customer | A customer account |
| <img src="icons/receipt.svg" width="16" height="16" alt="receipt icon"> | `receipt` | Order | A sales order |
| <img src="icons/layout-panel-top.svg" width="16" height="16" alt="layout-panel-top icon"> | `layout-panel-top` | CMS Block | A CMS static block |
| <img src="icons/file-text.svg" width="16" height="16" alt="file-text icon"> | `file-text` | CMS Page | A CMS page |
| <img src="icons/route.svg" width="16" height="16" alt="route icon"> | `route` | URL Rewrite | A storefront URL rewrite |
| <img src="icons/search-check.svg" width="16" height="16" alt="search-check icon"> | `search-check` | Search Term | A logged storefront search term |
| <img src="icons/store.svg" width="16" height="16" alt="store icon"> | `store` | Store View | A store view / website / store group |
| <img src="icons/list.svg" width="16" height="16" alt="list icon"> | `list` | Product Attribute | A product attribute |
| <img src="icons/layers.svg" width="16" height="16" alt="layers icon"> | `layers` | Attribute Set | A product attribute set |
| <img src="icons/user-cog.svg" width="16" height="16" alt="user-cog icon"> | `user-cog` | Admin User | An admin user account |
| <img src="icons/shield-check.svg" width="16" height="16" alt="shield-check icon"> | `shield-check` | Admin Role | An admin role (ACL resource set) |
| <img src="icons/users.svg" width="16" height="16" alt="users icon"> | `users` | Customer Group | A customer group |
| <img src="icons/star.svg" width="16" height="16" alt="star icon"> | `star` | Review | A product review |
| <img src="icons/ticket-percent.svg" width="16" height="16" alt="ticket-percent icon"> | `ticket-percent` | Cart Price Rule | A cart price rule (including its coupon codes as a distinct sub-type, below) |
| <img src="icons/badge-percent.svg" width="16" height="16" alt="badge-percent icon"> | `badge-percent` | Catalog Price Rule | A catalog price rule |
| <img src="icons/qr-code.svg" width="16" height="16" alt="qr-code icon"> | `qr-code` | Coupon Code | A specific coupon code belonging to a cart price rule |
| <img src="icons/puzzle.svg" width="16" height="16" alt="puzzle icon"> | `puzzle` | Widget | A CMS widget instance |
| <img src="icons/mail.svg" width="16" height="16" alt="mail icon"> | `mail` | Email Template | A transactional email template |
| <img src="icons/megaphone.svg" width="16" height="16" alt="megaphone icon"> | `megaphone` | Newsletter Template | A newsletter template |
| <img src="icons/inbox.svg" width="16" height="16" alt="inbox icon"> | `inbox` | Newsletter Subscriber | A newsletter subscriber |
| <img src="icons/braces.svg" width="16" height="16" alt="braces icon"> | `braces` | Custom Variable | A custom variable |
| <img src="icons/arrow-left-right.svg" width="16" height="16" alt="arrow-left-right icon"> | `arrow-left-right` | Search Synonym | A search synonym group |
| <img src="icons/calculator.svg" width="16" height="16" alt="calculator icon"> | `calculator` | Tax Rule | A tax rule |
| <img src="icons/percent.svg" width="16" height="16" alt="percent icon"> | `percent` | Tax Rate | A tax rate |
| <img src="icons/plug.svg" width="16" height="16" alt="plug icon"> | `plug` | Integration | An API integration |
| <img src="icons/receipt-text.svg" width="16" height="16" alt="receipt-text icon"> | `receipt-text` | Invoice | A sales invoice |
| <img src="icons/truck.svg" width="16" height="16" alt="truck icon"> | `truck` | Shipment | A sales shipment |
| <img src="icons/undo.svg" width="16" height="16" alt="undo icon"> | `undo` | Credit Memo | A credit memo |
| <img src="icons/history.svg" width="16" height="16" alt="history icon"> | `history` | Admin Activity | An admin activity log entry |
| <img src="icons/link.svg" width="16" height="16" alt="link icon"> | `link` | *(fallback)* | Used only if a result somehow has no type-specific icon — should not normally appear |

## Result-row action icons

Beyond the leading type icon, each row can show up to four small action icons on the right,
depending on what that result type supports — numbered here to match the screenshot below:

![Numbered action icons on a category result](screenshots/04-result-actions.png)

| # | Icon | Action | Shown for |
|---|---|---|---|
| 1 | <img src="icons/star.svg" width="14" height="14" alt="star icon" style="vertical-align:text-bottom"> star | Pin or unpin | Every result type |
| 2 | <img src="icons/pencil.svg" width="14" height="14" alt="pencil icon" style="vertical-align:text-bottom"> pencil | Edit | Types with a direct admin edit page |
| 3 | <img src="icons/grid.svg" width="14" height="14" alt="grid icon" style="vertical-align:text-bottom"> grid | View products | Categories only — jumps to the category's product grid instead of its edit page |
| 4 | <img src="icons/external-link.svg" width="14" height="14" alt="external-link icon" style="vertical-align:text-bottom"> external link | View on storefront | Products, categories, and CMS pages that are actually visible on the storefront |
| — | <img src="icons/globe.svg" width="14" height="14" alt="globe icon" style="vertical-align:text-bottom"> globe | Choose scope | Store-scoped settings with more than one configured value across scopes (replaces the pencil, not shown in the screenshot above) |

Picking an entry from the scope picker (🌐) navigates straight to that field's admin edit page
already switched to the chosen scope (default / website / store view) — it lands you directly on
the store-view-specific override, not the default value. The storefront picker (↗) is unrelated:
it opens the *public* storefront page for the record, not an admin edit page.

See [`docs/USER_GUIDE.md`](USER_GUIDE.md) for what each of these actually does when clicked.
