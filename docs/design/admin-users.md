# Admin panel — Users page (UI draft)

This is a design note, not part of the official documentation. The high-level description lives in [admin.md](../apps/admin.md).

## Users page layout

Header has the tab name **Users** on the left. On the right: a search field ("Search users") and the **Add user** button.

The page has two main sections:

### 1. User list (left)

Table columns:
- Username
- Display name
- e-mail
- Actions (kebab menu)

The table can be filtered by Username, Display name or e-mail.

Kebab menu actions:
- Edit
- Enable/Disable
- Delete

### 2. Add User modal

Triggered by the **Add user** button. Fields:
- Username
- Display name
- Email
- Password (hidden, with show/hide icon)
- Confirm password (with show/hide icon)
- Cancel and Save buttons

### 3. User details (right, shown when a user is selected)

Divided into three sections.

#### User
- Username (example: `test_user`)
- Display name (example: `QA Test`)
- Email (example: `qa_test@email.com`)
- Principal (example: `principals/test_user`)
- Calendars count (example: `3`)
- Events/Objects count (example: `2812`)
- Address Books count (example: `1`)
- Contacts count (example: `93`)

#### Calendars
Table columns:
- URI (example: `holidays-ca`)
- Name (example: `Canadian Holidays`)
- Objects (example: `38 TASKS NOTES`)
- DAV path (example: `/dav.php/calendars/test_user/holidays-ca/`)
- Actions (Edit, Delete)

**Add Calendar** button at the top of the section.

Add Calendar modal fields:
- URI token id (prefilled with a default)
- Display name
- Description
- Color (`#RRGGBB`)
- [ ] Tasks (VTODO)
- [ ] Notes (VJOURNAL)
- Cancel and Save buttons

#### Address books
Table columns:
- URI (example: `default`)
- Name (example: `Personal Address Book`)
- Contacts (example: `93`)
- Actions (Edit, Delete)

**Add address book** button at the top of the section.

Add address book modal fields:
- URI token id (prefilled with a default)
- Display name
- Description
- Cancel and Save buttons

---

Examples in the original draft were shown in `{}`.
